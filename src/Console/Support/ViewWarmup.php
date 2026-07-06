<?php

namespace Nitro\Console\Support;

use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Nitro\View\Blade;
use Nitro\View\Support\ViewManifest;

/**
 * Precompiles the application's Blade views and builds the opcache warmup bundle.
 *
 * Extracted from OptimizeCommand so the optimize command orchestrates steps while
 * this class owns the view step: compile every view, write the per-view stream
 * manifest, and emit cache/views_warmup.php (which the runtime includes once per
 * request to opcache-prime all compiled views). Parse-check results are memoized on
 * disk by compiled-path + mtime, so unchanged views are never re-validated.
 */
class ViewWarmup
{
    public const META_FILE = 'views_warmup_meta.php';

    public function __construct(
        private PathRegistry $paths,
        private Blade $view,
        private ConfigRepository $config,
        private OutputFormatter $output,
    ) {}

    /** Compile all views, write the manifest, and build the opcache warmup bundle. */
    public function compile(): void
    {
        try {
            $viewRenderer  = $this->view;
            $viewsPath     = $this->paths->views();
            $viewFiles     = $this->getAllViewFiles($viewsPath);
            $cachedCount   = 0;
            $compiledPaths = [];
            $manifest      = [];

            foreach ($viewFiles as $viewFile) {
                $viewName = $this->getViewNameFromPath($viewFile, $viewsPath);
                try {
                    $viewRenderer->compileOnly($viewName);
                    $cachedCount++;

                    // Cheap source peek — same 256-byte window the runtime
                    // would use as a fallback, captured here once at
                    // build time so request-time never has to.
                    $head = @file_get_contents($viewFile, false, null, 0, 256);
                    $manifest[$viewName] = [
                        'stream' => is_string($head) && str_contains($head, '@stream'),
                    ];

                    // Collect the compiled cache file path for the warmup bundle.
                    if (method_exists($viewRenderer, 'getCompiledPath')) {
                        $compiledPath = $viewRenderer->getCompiledPath($viewName);
                    } else {
                        $compiledPath = $this->guessCompiledPath($viewName);
                    }
                    if ($compiledPath && is_file($compiledPath)) {
                        $compiledPaths[] = $compiledPath;
                    }
                } catch (\Throwable $e) {
                    $this->output->writeln($this->output->color("  ✖ Failed to compile view: {$viewName}", 'red'));
                    $this->output->writeln($this->output->color("    Error: " . $e->getMessage(), 'red'));
                }
            }

            $this->output->writeln($this->output->color("  ✓ Cached {$cachedCount} views", 'green'));

            // Persist the per-view flag manifest so ViewRenderer::isStreamView
            // can answer from one autoload instead of stat'ing every source.
            if (ViewManifest::write($manifest)) {
                $this->output->writeln($this->output->color(
                    "  ✓ Wrote view manifest (" . count($manifest) . " entries)",
                    'green'
                ));
            }

            // Write the opcache warmup bundle — RegisterProviders includes
            // this on every web request, priming opcache for ALL compiled
            // views in one shot.
            $this->writeViewWarmupBundle($compiledPaths);
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color("  ✖ View cache fatal error: " . $e->getMessage(), 'red'));
        }
    }

    /**
     * Generate cache/views_warmup.php — a tiny file the runtime includes once per
     * request to opcache-prime every compiled view. Parse-checks candidates before
     * bundling, memoizing results by compiled-path + mtime to avoid re-checking
     * unchanged views on every optimize run.
     *
     * @param  string[]  $compiledPaths  Absolute paths to compiled view files.
     */
    private function writeViewWarmupBundle(array $compiledPaths): void
    {
        if (empty($compiledPaths)) {
            return;
        }

        $compiledPaths = array_values(array_unique($compiledPaths));
        $meta = $this->loadViewWarmupMeta();
        $nextMeta = [];
        $safe = [];
        $broken = [];
        $reusedChecks = 0;
        $freshChecks = 0;

        foreach ($compiledPaths as $path) {
            $mtime = @filemtime($path);

            if ($mtime === false) {
                $broken[] = $path;
                continue;
            }

            $cached = $meta[$path] ?? null;
            if (
                is_array($cached)
                && ($cached['mtime'] ?? null) === $mtime
                && array_key_exists('valid', $cached)
            ) {
                $valid = (bool) $cached['valid'];
                $reusedChecks++;
            } else {
                $valid = $this->compiledViewLints($path);
                $freshChecks++;
            }

            $nextMeta[$path] = [
                'mtime' => $mtime,
                'valid' => $valid,
            ];

            if ($valid) {
                $safe[] = $path;
            } else {
                $broken[] = $path;
            }
        }

        $this->storeViewWarmupMeta($nextMeta);

        foreach ($broken as $path) {
            $this->output->writeln($this->output->color(
                "  ⚠ Skipping broken compiled view in warmup: " . basename($path),
                'yellow'
            ));
        }

        if (empty($safe)) {
            return;
        }

        $paths = $this->paths;
        $bundle = $paths->cache('views_warmup.php');

        $exported = var_export($safe, true);
        $contents = <<<PHP
<?php

// Auto-generated by `nitro optimize` — do not edit.
// Calls opcache_compile_file() on every compiled view so the first web
// request primes opcache for all of them in one shot. Subsequent calls
// are no-ops (opcache_is_script_cached short-circuits).

if (!function_exists('opcache_compile_file') || !function_exists('opcache_is_script_cached')) {
    return;
}

\$__nitroViewFiles = {$exported};

foreach (\$__nitroViewFiles as \$__f) {
    if (is_file(\$__f) && !@opcache_is_script_cached(\$__f)) {
        @opcache_compile_file(\$__f);
    }
}

unset(\$__nitroViewFiles, \$__f);
PHP;

        if (@file_put_contents($bundle, $contents) !== false) {
            $this->output->writeln($this->output->color(
                "  ✓ Wrote opcache warmup bundle (" . count($safe) . " views)",
                'green'
            ));
            $this->output->writeln($this->output->color(
                "    Reused {$reusedChecks} validation results, checked {$freshChecks} changed views",
                'cyan'
            ));
        }
    }

    /**
     * Parse-check a compiled view file. Prefers in-process opcache compilation when
     * CLI opcache is enabled; otherwise eval-parses the file without executing it.
     */
    private function compiledViewLints(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        if ($this->canLintCompiledViewsInProcess()) {
            return (bool) @opcache_compile_file($path);
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        try {
            eval("if (false) { ?>" . $contents . "<?php }");
            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    /** Whether compiled views can be opcache-lint-checked in this process. */
    private function canLintCompiledViewsInProcess(): bool
    {
        if (!function_exists('opcache_compile_file')) {
            return false;
        }

        if (PHP_SAPI !== 'cli') {
            return true;
        }

        $enabled = ini_get('opcache.enable_cli');

        return $enabled === '1' || strtolower((string) $enabled) === 'on';
    }

    /**
     * @return array<string, array{mtime:int, valid:bool}>
     */
    private function loadViewWarmupMeta(): array
    {
        $path = $this->paths->cache(self::META_FILE);

        if (!is_file($path)) {
            return [];
        }

        $meta = require $path;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, array{mtime:int|false, valid:bool}> $meta
     */
    private function storeViewWarmupMeta(array $meta): void
    {
        $path = $this->paths->cache(self::META_FILE);
        $contents = "<?php\n\n// Auto-generated by `nitro optimize` — do not edit.\n\nreturn "
            . var_export($meta, true) . ";\n";

        @file_put_contents($path, $contents);
    }

    /**
     * Fallback path computation when the view renderer doesn't expose a
     * getCompiledPath() helper. Mirrors CompiledTemplateCache::getCacheFilePath.
     */
    private function guessCompiledPath(string $view): string
    {
        $cachePath = $this->paths->cache('views');
        return $cachePath . DIRECTORY_SEPARATOR . md5($view . $cachePath) . '.php';
    }

    /** Recursively collect every template file under the views directory. */
    private function getAllViewFiles(string $directory): array
    {
        $viewFiles = [];
        $extension = $this->config->get('view.extension');

        if (!is_dir($directory)) {
            return $viewFiles;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $viewFiles[] = $file->getPathname();
            }
        }

        return $viewFiles;
    }

    /** Convert an absolute template path into its dot-notation view name. */
    private function getViewNameFromPath(string $filePath, string $viewsPath): string
    {
        $extension    = $this->config->get('view.extension');
        $relativePath = str_replace($viewsPath . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace('.' . $extension, '', $relativePath);
        return str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);
    }
}
