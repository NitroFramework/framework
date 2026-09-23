<?php

namespace Nitro\View\Compiler;

use Closure;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\ResetsBetweenRequests;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use RuntimeException;

/**
 * Stores the compiled form of each template and decides when it is stale.
 *
 * Artefacts are keyed by the template's own path, never by the view name: one
 * name can resolve to different files.
 */
class CompiledTemplateCache implements TemplateCache, ResetsBetweenRequests
{
    /** Directory holding every compiled template. */
    private string $cachePath;

    /** Whether compiled templates are kept at all, or recompiled every render. */
    private bool $cacheEnabled;

    /**
     * Treat a compiled template older than this many seconds as stale
     * regardless of its source's timestamp. Zero disables the check.
     */
    private int $cacheExpiry;

    /** Whether compiled templates are primed into opcache as they are written. */
    private bool $useOpCache;

    /**
     * Whether writes are guarded by a lock file. Only worth its cost where many
     * processes may compile the same template at once.
     */
    private bool $useFileLocks;

    /** Whether opcache priming is both wanted and actually available. */
    private bool $opcacheAvailable;

    /** Whether the application is in debug, which changes what may be memoized. */
    private bool $debug;

    /**
     * Cache directories already verified this process.
     *
     * @var array<string, bool>
     */
    private static array $verifiedDirs = [];

    /**
     * Memoized freshness verdicts, so a view rendered several times in one
     * request — a layout, a partial, a component — is stat'd once.
     *
     * @var array<string, bool>
     */
    private array $freshnessCache = [];

    /**
     * A null `view.cache.use_opcache` means decide from the environment: prime
     * compiled views into opcache in production, and leave it alone in debug,
     * where invalidating a template just edited is what matters. An explicit
     * true or false still wins.
     */
    public function __construct(
        private Closure|TemplateCompiler $compiler,
        PathRegistry $paths,
        ConfigRepository $config,
        private ?TemplateCompilers $compilers = null,
    ) {
        $this->cachePath    = $paths->cache('views');
        $this->cacheEnabled = (bool) $config->get('view.cache.enabled');
        $this->cacheExpiry  = (int) $config->get('view.cache.expiry');
        $this->useFileLocks = (bool) $config->get('view.cache.use_locks');
        $this->debug        = (bool) $config->get('app.debug', false);

        $configured = $config->get('view.cache.use_opcache');

        $this->useOpCache = $configured === null ? ! $this->debug : (bool) $configured;

        $this->opcacheAvailable = $this->useOpCache && function_exists('opcache_is_script_cached');

        if ($this->cacheEnabled) {
            $this->ensureCacheDirectoryExists();
        }
    }

    // ─── The cache's surface ──────────────────────────────

    /**
     * The path of the compiled template, compiling it first if what is on disk
     * is missing or older than its source.
     *
     * With caching off the compiled PHP still has to live somewhere a
     * `require` can reach, so it goes to a temp file removed at shutdown.
     *
     * @param  string $templateFile Absolute path to the source template.
     * @param  string $view         The name it was requested under, for diagnostics.
     * @return string Absolute path to a PHP file ready to include.
     */
    public function resolve(string $templateFile, string $view): string
    {
        $cacheFile = $this->getCacheFilePath($templateFile);

        if ($this->isFresh($templateFile, $cacheFile)) {
            return $cacheFile;
        }

        $compiled = $this->compileSourceToPhp($templateFile);

        if (! $this->cacheEnabled) {
            return $this->persistToTempFile($compiled);
        }

        if ($this->useFileLocks) {
            return $this->persistToCacheWithLock($compiled, $templateFile, $cacheFile);
        }

        $this->persistToCache($compiled, $templateFile, $cacheFile);

        return $cacheFile;
    }

    /**
     * Compile a template without returning anything to include.
     *
     * @param string $templateFile Absolute path to the source template.
     * @param string $view         The name it is known by.
     */
    public function compile(string $templateFile, string $view): void
    {
        $cacheFile = $this->getCacheFilePath($templateFile);

        if (! $this->isFresh($templateFile, $cacheFile)) {
            $compiled = $this->compileSourceToPhp($templateFile);
            $this->persistToCacheWithLock($compiled, $templateFile, $cacheFile);
        }
    }

    /**
     * Discard every compiled template, removing each from opcache first so a
     * long-running worker does not keep serving bytecode for a deleted file.
     */
    public function clear(): void
    {
        if (! is_dir($this->cachePath)) {
            return;
        }

        foreach (glob($this->cachePath . '/*.php') ?: [] as $file) {
            if (is_file($file)) {
                $this->removeFromOpcache($file);
                @unlink($file);
            }
        }
    }

    /**
     * Discard the compiled form of one template.
     *
     * @param string $templateFile Absolute path to the source template.
     */
    public function clearView(string $templateFile): void
    {
        $cacheFile = $this->getCacheFilePath($templateFile);

        if (file_exists($cacheFile)) {
            $this->removeFromOpcache($cacheFile);
            @unlink($cacheFile);
        }
    }

    /**
     * Get where the compiled form of a template is kept.
     *
     * @param string $templateFile Absolute path to the source template.
     */
    public function getCacheFilePath(string $templateFile): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . md5($templateFile . $this->cachePath) . '.php';
    }

    /**
     * Counts and sizes describing what the cache currently holds.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        if (! is_dir($this->cachePath)) {
            return [
                'enabled'              => $this->cacheEnabled,
                'path'                 => $this->cachePath,
                'files'                => 0,
                'total_size'           => 0,
                'total_size_formatted' => '0 B',
                'opcache_enabled'      => $this->opcacheAvailable,
                'opcache_cached'       => 0,
            ];
        }

        $files         = glob($this->cachePath . '/*.php') ?: [];
        $totalSize     = 0;
        $opcacheCached = 0;

        foreach ($files as $file) {
            $totalSize += (int) @filesize($file);

            if ($this->isLoadedInOpcache($file)) {
                $opcacheCached++;
            }
        }

        return [
            'enabled'              => $this->cacheEnabled,
            'path'                 => $this->cachePath,
            'files'                => count($files),
            'total_size'           => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
            'opcache_enabled'      => $this->opcacheAvailable,
            'opcache_cached'       => $opcacheCached,
        ];
    }

    /**
     * Turn caching on or off, so a template is recompiled on every render.
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Treat a compiled template older than this as stale regardless of its
     * source's timestamp. Zero disables the check.
     */
    public function setCacheExpiry(int $seconds): void
    {
        $this->cacheExpiry = $seconds;
    }

    // ─── Compilation ──────────────────────────────────────

    /**
     * Read a template and hand its source to the compiler its extension names.
     *
     * @throws RuntimeException When the template cannot be read.
     */
    private function compileSourceToPhp(string $templateFile): string
    {
        $source = file_get_contents($templateFile);

        if ($source === false) {
            throw new RuntimeException("Failed to read template file: {$templateFile}");
        }

        $compiler = $this->compilers?->for($templateFile) ?? $this->defaultCompiler();

        return $compiler->compile($source);
    }

    /**
     * The compiler for a template no registered extension claims.
     *
     * Given as a closure so that resolving this cache does not build it. A
     * render served from the compiled cache never reaches here, and the Blade
     * compiler flattens sixteen traits — seventeen files to answer a question
     * about a file's modification time.
     */
    private function defaultCompiler(): TemplateCompiler
    {
        if ($this->compiler instanceof Closure) {
            $this->compiler = ($this->compiler)();
        }

        return $this->compiler;
    }

    // ─── Persistence ──────────────────────────────────────

    /**
     * Put compiled PHP somewhere includable when caching is off.
     *
     * @throws RuntimeException When no temporary file can be created.
     */
    private function persistToCache(string $compiled, string $templateFile, string $cacheFile): void
    {
        $this->writeFileAtomically($compiled, $cacheFile);
        $this->primeOpcache($cacheFile);
    }

    /**
     * Write to a unique temporary name and move it into place.
     *
     * A rename is atomic, so a concurrent reader never sees a half-written
     * template. A lost rename race is a success, not an error.
     *
     * @throws RuntimeException When the compiled template cannot be written.
     */
    private function persistToCacheWithLock(string $compiled, string $templateFile, string $cacheFile): string
    {
        $lockFile = $cacheFile . '.lock';
        $lock     = @fopen($lockFile, 'c+');

        if ($lock === false) {
            $this->persistToCache($compiled, $templateFile, $cacheFile);

            return $cacheFile;
        }

        try {
            if (flock($lock, LOCK_EX)) {
                if (! $this->isFresh($templateFile, $cacheFile)) {
                    $this->persistToCache($compiled, $templateFile, $cacheFile);
                }

                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
            @unlink($lockFile);
        }

        return $cacheFile;
    }

    /**
     * Put compiled PHP somewhere a require can reach when caching is off.
     *
     * The file is removed at shutdown, so nothing accumulates.
     *
     * @throws RuntimeException When no temporary file can be created.
     */
    private function persistToTempFile(string $compiled): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'nitro_blade_');

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for template compilation');
        }

        file_put_contents($tempFile, $compiled);
        $this->registerTempFileCleanup($tempFile);

        return $tempFile;
    }

    /**
     * Determine whether the compiled file may still be used.
     *
     * Memoized, since one request renders the same layout and partials
     * repeatedly and each verdict costs a pair of stats.
     */
    private function writeFileAtomically(string $compiled, string $cacheFile): void
    {
        $tmp = $cacheFile . '.tmp.' . uniqid((string) getmypid() . '_', true);

        if (file_put_contents($tmp, $compiled, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write compiled template to: {$tmp}");
        }

        if (! @rename($tmp, $cacheFile)) {
            @unlink($tmp);

            if (! file_exists($cacheFile)) {
                throw new RuntimeException(
                    'Failed to move compiled template into place. '
                        . "Check permissions for: {$cacheFile}"
                );
            }
        }
    }

    /**
     * Remove a temporary compiled file once the process ends.
     */
    private function registerTempFileCleanup(string $tempFile): void
    {
        register_shutdown_function(static function () use ($tempFile): void {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        });
    }

    /**
     * Create the cache directory if it is missing, and confirm it is writable.
     *
     * @throws RuntimeException When the directory cannot be created or written to.
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (isset(self::$verifiedDirs[$this->cachePath])) {
            return;
        }

        if (! is_dir($this->cachePath)) {
            if (! mkdir($this->cachePath, 0755, true) && ! is_dir($this->cachePath)) {
                throw new RuntimeException("Failed to create cache directory: {$this->cachePath}");
            }
        }

        if (! is_writable($this->cachePath)) {
            throw new RuntimeException("Cache directory is not writable: {$this->cachePath}");
        }

        self::$verifiedDirs[$this->cachePath] = true;
    }

    // ─── Freshness ────────────────────────────────────────

    /**
     * Forget freshness verdicts between requests, in debug only.
     *
     * In production a source cannot change beneath a worker, so clearing would
     * cost a stat per template per render and buy nothing.
     */
    private function isFresh(string $templateFile, string $cacheFile): bool
    {
        $key = $cacheFile . '|' . $templateFile;

        if (isset($this->freshnessCache[$key])) {
            return $this->freshnessCache[$key];
        }

        $cacheTime = @filemtime($cacheFile);

        if ($cacheTime === false) {
            return $this->freshnessCache[$key] = false;
        }

        $sourceTime = @filemtime($templateFile);

        if ($sourceTime === false || $cacheTime < $sourceTime) {
            return $this->freshnessCache[$key] = false;
        }

        if ($this->cacheExpiry !== 0 && (time() - $cacheTime) > $this->cacheExpiry) {
            return $this->freshnessCache[$key] = false;
        }

        return $this->freshnessCache[$key] = true;
    }

    /**
     * Drop memoized freshness verdicts, for test harnesses and long-running
     * workers that need the next render to look at the filesystem again.
     */
    public function clearFreshnessCache(): void
    {
        $this->freshnessCache = [];
    }

    /**
     * Compile a template into opcache as it is written.
     */
    public function resetBetweenRequests(): void
    {
        if ($this->debug) {
            $this->clearFreshnessCache();
        }
    }

    // ─── Opcache ──────────────────────────────────────────

    /**
     * Whether opcache already holds bytecode for a compiled template.
     */
    private function isLoadedInOpcache(string $cacheFile): bool
    {
        return $this->opcacheAvailable && (bool) @opcache_is_script_cached($cacheFile);
    }

    /**
     * Compile a template into opcache as soon as it is written, so the request
     * that triggered the compilation is the only one that pays to parse it.
     */
    private function primeOpcache(string $cacheFile): void
    {
        if ($this->opcacheAvailable && function_exists('opcache_compile_file')) {
            @opcache_compile_file($cacheFile);
        }
    }

    /**
     * Drop a compiled template's bytecode, so a deleted or replaced file is not
     * still served from memory.
     */
    private function removeFromOpcache(string $cacheFile): void
    {
        if ($this->opcacheAvailable && function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
    }

    // ─── Formatting ───────────────────────────────────────

    /**
     * A byte count in the largest unit that leaves it readable.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2) . ' ' . $units[$index];
    }
}
