<?php

namespace Nitro\View\Compiler;

use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\TemplateCache;

use RuntimeException;

/**
 * Stores and resolves compiled template files, recompiling when the source changes.
 */
class CompiledTemplateCache implements TemplateCache
{
    private string $cachePath;
    private bool $cacheEnabled;
    private int $cacheExpiry;
    private bool $useOpCache;
    private bool $useFileLocks;
    private bool $opcacheAvailable;

    public function __construct(
        private TemplateCompiler $compiler,
        PathRegistry $paths,
        ConfigRepository $config
    ) {
        // Pull everything from the objects the container provided
        $this->cachePath    = $paths->cache('views');
        $this->cacheEnabled = (bool) $config->get('view.cache.enabled');
        $this->cacheExpiry  = (int)  $config->get('view.cache.expiry');
        $this->useOpCache   = (bool) $config->get('view.cache.use_opcache');
        $this->useFileLocks = (bool) $config->get('view.cache.use_locks');

        $this->opcacheAvailable = $this->useOpCache && function_exists('opcache_is_script_cached');

        if ($this->cacheEnabled) {
            $this->ensureCacheDirectoryExists();
        }
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function resolve(string $templateFile, string $view): string
    {
        $cacheFile = $this->getCacheFilePath($view);

        // Concern 3 — is what we already have still valid?
        if ($this->isFresh($templateFile, $cacheFile)) {
            return $cacheFile;
        }

        // Concern 1 — compile source to PHP
        $compiled = $this->compileSourceToPhp($templateFile);

        // Concern 2 — persist it
        if (!$this->cacheEnabled) {
            return $this->persistToTempFile($compiled);
        }

        if ($this->useFileLocks) {
            return $this->persistToCacheWithLock($compiled, $templateFile, $cacheFile);
        }

        $this->persistToCache($compiled, $templateFile, $cacheFile);

        return $cacheFile;
    }

    public function compile(string $templateFile, string $view): void
    {
        $cacheFile = $this->getCacheFilePath($view);

        if (!$this->isFresh($templateFile, $cacheFile)) {
            $compiled = $this->compileSourceToPhp($templateFile);
            $this->persistToCacheWithLock($compiled, $templateFile, $cacheFile);
        }
    }

    public function clear(): void
    {
        if (!is_dir($this->cachePath)) {
            return;
        }

        foreach (glob($this->cachePath . '/*.php') ?: [] as $file) {
            if (is_file($file)) {
                $this->removeFromOpcache($file);
                @unlink($file);
            }
        }
    }

    public function clearView(string $view): void
    {
        $cacheFile = $this->getCacheFilePath($view);

        if (file_exists($cacheFile)) {
            $this->removeFromOpcache($cacheFile);
            @unlink($cacheFile);
        }
    }

    public function getCacheFilePath(string $view): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . md5($view . $this->cachePath) . '.php';
    }

    public function getStats(): array
    {
        if (!is_dir($this->cachePath)) {
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

    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    public function setCacheExpiry(int $seconds): void
    {
        $this->cacheExpiry = $seconds;
    }

    // =========================================================================
    // CONCERN 1 — COMPILATION
    // Blade source → executable PHP string.
    // No knowledge of files, paths, or caching.
    // =========================================================================

    private function compileSourceToPhp(string $templateFile): string
    {
        $source = file_get_contents($templateFile);

        if ($source === false) {
            throw new RuntimeException("Failed to read template file: {$templateFile}");
        }

        return $this->compiler->compile($source, $templateFile);
    }

    // =========================================================================
    // CONCERN 2 — PERSISTENCE
    // Where does the compiled PHP live and how does it get written there.
    // Two strategies: permanent cache file, or temp file cleaned at shutdown.
    // =========================================================================

    private function persistToCache(string $compiled, string $templateFile, string $cacheFile): void
    {
        $this->writeFileAtomically($compiled, $cacheFile);
        // Do NOT align mtime to the source. The natural "now" timestamp
        // from writing the file makes apache/FPM opcache invalidate
        // reliably across long-running workers — without it, a re-compile
        // can leave the cache file's mtime in the past, opcache never
        // notices the change, and serves stale bytecode until a server
        // restart. (Framework freshness checks still pass because they
        // ask cacheTime >= sourceTime, which always holds when the cache
        // was written after the source was read.)
        $this->primeOpcache($cacheFile);
    }

    private function persistToCacheWithLock(string $compiled, string $templateFile, string $cacheFile): string
    {
        $lockFile = $cacheFile . '.lock';
        $lock     = @fopen($lockFile, 'c+');

        if ($lock === false) {
            // Cannot open lock file — fall back to writing without lock
            $this->persistToCache($compiled, $templateFile, $cacheFile);
            return $cacheFile;
        }

        try {
            if (flock($lock, LOCK_EX)) {
                // Re-check inside lock — another process may have written while we waited
                if (!$this->isFresh($templateFile, $cacheFile)) {
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

    private function persistToTempFile(string $compiled): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'nitro_blade_');

        if ($tempFile === false) {
            throw new RuntimeException("Failed to create temporary file for template compilation");
        }

        file_put_contents($tempFile, $compiled);
        $this->registerTempFileCleanup($tempFile);

        return $tempFile;
    }

    private function writeFileAtomically(string $compiled, string $cacheFile): void
    {
        $tmp = $cacheFile . '.tmp.' . uniqid((string) getmypid() . '_', true);

        if (file_put_contents($tmp, $compiled, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write compiled template to: {$tmp}");
        }

        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);

            if (!file_exists($cacheFile)) {
                throw new RuntimeException(
                    "Failed to move compiled template into place. " .
                        "Check permissions for: {$cacheFile}"
                );
            }
        }
    }

    private function registerTempFileCleanup(string $tempFile): void
    {
        register_shutdown_function(static function () use ($tempFile): void {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        });
    }

    /**
     * Track which cache directories have already been validated this process.
     * In worker mode the constructor runs once anyway; in classic mode each
     * request still only pays one stat instead of an is_dir + is_writable pair.
     */
    private static array $verifiedDirs = [];

    private function ensureCacheDirectoryExists(): void
    {
        if (isset(self::$verifiedDirs[$this->cachePath])) {
            return;
        }

        if (!is_dir($this->cachePath)) {
            if (!mkdir($this->cachePath, 0755, true) && !is_dir($this->cachePath)) {
                throw new RuntimeException("Failed to create cache directory: {$this->cachePath}");
            }
        }

        if (!is_writable($this->cachePath)) {
            throw new RuntimeException("Cache directory is not writable: {$this->cachePath}");
        }

        self::$verifiedDirs[$this->cachePath] = true;
    }

    // =========================================================================
    // CONCERN 3 — FRESHNESS
    // Is what is already persisted still valid?
    // Three independent checks composed into one answer.
    // =========================================================================

    /**
     * Memoize freshness verdicts for the lifetime of the request so views
     * rendered multiple times (partials, layouts, included components) only
     * pay the stat cost once.
     *
     * @var array<string, bool>
     */
    private array $freshnessCache = [];

    /**
     * Issue a single pair of stats per template+cache combo instead of three
     * independent calls (file_exists + 2× filemtime). Verdict is then cached
     * for the request lifetime.
     */
    private function isFresh(string $templateFile, string $cacheFile): bool
    {
        $key = $cacheFile . '|' . $templateFile;
        if (isset($this->freshnessCache[$key])) {
            return $this->freshnessCache[$key];
        }

        $cacheTime = @filemtime($cacheFile);
        if ($cacheTime === false) {
            return $this->freshnessCache[$key] = false; // cache file missing
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

    /** Drop memoized freshness verdicts (test harnesses, long-running workers). */
    public function clearFreshnessCache(): void
    {
        $this->freshnessCache = [];
    }

    private function isLoadedInOpcache(string $cacheFile): bool
    {
        return $this->opcacheAvailable && (bool) @opcache_is_script_cached($cacheFile);
    }

    private function primeOpcache(string $cacheFile): void
    {
        if ($this->opcacheAvailable && function_exists('opcache_compile_file')) {
            @opcache_compile_file($cacheFile);
        }
    }

    private function removeFromOpcache(string $cacheFile): void
    {
        if ($this->opcacheAvailable && function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
