<?php

namespace Nitro\Console\Commands;

use Nitro\Foundation\ModuleManifest;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Cache\CacheManager;
use Nitro\Console\OutputFormatter;
use Nitro\Console\Support\ViewWarmup;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Foundation\Application;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\RouteLoader;
use Nitro\View\Blade;

/**
 * Console commands: build (optimize) and clear (optimize:clear) the production
 * caches — config, routes, views, providers, schema, the helper bundle, an
 * opcache preload script, and an optimized/class-authoritative autoloader.
 * optimize:clear reverts all of them (and resets opcache / restores the dev
 * autoloader), so it's the single "back to development" verb.
 */
class OptimizeCommand implements CommandInterface
{
    public function __construct(
        private ContainerInterface $container,
        private OutputFormatter $output,
        private PathRegistry $paths,
        private Application $app,
        private Blade $view,
        private Config $config,
    ) {}

    public function getCommands(): array
    {
        return [
            'optimize'       => 'Cache configuration, routes, and views for maximum performance',
            'optimize:clear' => 'Clear all optimization caches'
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'optimize'       => $this->optimize(),
            'optimize:clear' => $this->clearOptimizations(),
            default          => $this->output->error("Unknown optimize command: {$command}")
        };
    }

    protected function optimize(): void
    {
        $this->output->writeln("");
        $this->output->writeln($this->output->color("========================================", 'cyan'));
        $this->output->writeln($this->output->color("  NITRO OPTIMIZATION", 'cyan', true));
        $this->output->writeln($this->output->color("========================================", 'cyan'));
        $this->output->writeln("");

        $startTime = microtime(true);

        $this->output->info("Step 1/8: Caching configuration...");
        $this->cacheConfig();

        $this->output->info("Step 2/8: Caching routes...");
        $this->cacheRoutes();

        $this->output->info("Step 3/8: Caching views...");
        $this->cacheViews();

        $this->output->info("Step 4/8: Caching service providers...");
        $this->cacheBootstrap();

        $this->output->info("Step 5/8: Bundling helpers...");
        $this->bundleHelpers();

        $this->output->info("Step 6/8: Caching database schema...");
        $this->cacheSchema();

        $this->output->info("Step 7/8: Generating opcache preload script...");
        $this->generatePreload();

        $this->output->info("Step 8/8: Dumping optimized autoloader...");
        $this->dumpAutoloader();

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->output->writeln("");
        $this->output->writeln($this->output->color("========================================", 'green'));
        $this->output->writeln($this->output->color("  ✓ OPTIMIZATION COMPLETE", 'green', true));
        $this->output->writeln($this->output->color("========================================", 'green'));
        $this->output->writeln($this->output->color("  Completed in {$duration}ms", 'green'));
        $this->output->writeln("");
        $this->output->writeln($this->output->color("Your application is now optimized for production!", 'cyan'));
        $this->output->writeln($this->output->color("Run 'php nitro optimize:clear' to revert.", 'yellow'));
        $this->output->writeln("");
    }

    protected function cacheConfig(): void
    {
        try {
            $paths        = $this->paths;
            // Load straight from config/*.php (ignore any existing cache) so we
            // capture the current source, not the cache we're about to overwrite.
            $config       = new Config($paths, true);
            $dropped      = [];
            $data         = $this->filterSerializable($config->all(), $dropped);
            $cacheContent = "<?php\n\nreturn " . var_export($data, true) . ";\n";
            file_put_contents($paths->cache('config.php'), $cacheContent);
            $this->output->writeln($this->output->color("  ✓ Configuration cached", 'green'));

            // Closures/objects can't be var_export'd, so they're dropped from the
            // cache — which means config() returns null for them in optimized
            // mode (a silent divergence from dev). Surface exactly which keys, so
            // it's a visible warning instead of a production surprise.
            if ($dropped !== []) {
                $this->output->writeln($this->output->color(
                    "  ⚠ " . count($dropped) . " non-serializable config value(s) omitted from the cache "
                    . "(config() returns null for these in optimized mode): " . implode(', ', $dropped),
                    'yellow'
                ));
            }
        } catch (\Exception $e) {
            $this->output->writeln($this->output->color("  ✖ Config cache failed: " . $e->getMessage(), 'red'));
        }
    }

    /**
     * Strip values var_export can't emit (closures/objects), recording their
     * dotted keys in $dropped so the caller can warn about the divergence.
     */
    protected function filterSerializable(array $data, array &$dropped = [], string $prefix = ''): array
    {
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if ($value instanceof \Closure || is_object($value)) {
                $dropped[] = $path;
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSerializable($value, $dropped, $path);
            }
        }
        return $data;
    }

    protected function cacheRoutes(): void
    {
        try {
            $routeLoader = $this->container->get(RouteLoader::class);
            $router       = $this->container->get(RouterInterface::class);
            $router->clearRoutes();
            $routeLoader->loadFromFile($router);

            $skippedClosures = $routeLoader->cache($router);
            if ($skippedClosures > 0) {
                $this->output->writeln($this->output->color(
                    "  ⚠ Route cache skipped: {$skippedClosures} closure route(s) can't be serialized. "
                    . "Convert them to controller actions to enable route caching.",
                    'yellow'
                ));
                return;
            }

            $routeCount = count($router->getRoutes(), COUNT_RECURSIVE);
            $this->output->writeln($this->output->color("  ✓ Cached {$routeCount} routes", 'green'));
        } catch (\Exception $e) {
            $this->output->writeln($this->output->color("  ✖ Route cache failed: " . $e->getMessage(), 'red'));
        }
    }

    protected function cacheViews(): void
    {
        (new ViewWarmup($this->paths, $this->view, $this->config, $this->output))->compile();
    }

    /**
     * Bundle every Helpers/*.php into a single Helpers/bundle.php so the
     * runtime loader only does one file open + one opcache lookup instead of
     * ~20. Order is preserved to honor dependency order between helper files.
     */
    protected function bundleHelpers(): void
    {
        try {
            $helpersDir = __DIR__ . '/../../Support/Helpers';
            // Derive the file list AND its dependency order from the runtime
            // loader itself (Support/helpers.php), so the bundle can never drift
            // out of sync with it. A hardcoded copy here previously omitted
            // cookie.php, leaving cookie() undefined in optimized mode.
            $loaderFile = __DIR__ . '/../../Support/helpers.php';
            $files = [];
            if (is_file($loaderFile)
                && preg_match_all('#/Helpers/([A-Za-z0-9_]+\.php)#', (string) file_get_contents($loaderFile), $m)
            ) {
                foreach ($m[1] as $f) {
                    if ($f !== 'bundle.php' && !in_array($f, $files, true)) {
                        $files[] = $f;
                    }
                }
            }

            // Helper files are concatenated into ONE file scope, so their
            // top-level `use` imports must be hoisted to the top and de-duped —
            // otherwise two files importing the same class (e.g. Container)
            // collide with "Cannot use … because the name is already in use".
            $useStatements = [];
            $bodies = '';

            foreach ($files as $file) {
                $path = $helpersDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($path)) {
                    continue;
                }
                $contents = file_get_contents($path);
                // Strip the leading <?php tag so we can concatenate.
                $contents = preg_replace('/^\s*<\?php\s*/i', '', $contents, 1);

                // Lift top-level `use ...;` lines out of the body (line-anchored,
                // so closure `use (...)` clauses are never matched) and collect
                // them de-duped. Keyed by statement => emitted once.
                $contents = preg_replace_callback(
                    '/^use\s+[^;]+;[ \t]*\r?\n/m',
                    function (array $m) use (&$useStatements): string {
                        $useStatements[trim($m[0])] = true;
                        return '';
                    },
                    $contents,
                );

                $bodies .= "// --- {$file} ---\n" . $contents . "\n";
            }

            $bundled = "<?php\n\n// Auto-generated by `nitro optimize` — do not edit.\n// Run `nitro optimize:clear` to remove.\n\n";
            if ($useStatements !== []) {
                $bundled .= implode("\n", array_keys($useStatements)) . "\n\n";
            }
            $bundled .= $bodies;

            file_put_contents($helpersDir . '/bundle.php', $bundled);
            $this->output->writeln($this->output->color("  ✓ Bundled " . count($files) . " helper files", 'green'));
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color("  ✖ Helper bundling failed: " . $e->getMessage(), 'red'));
        }
    }

    protected function cacheBootstrap(): void
    {
        try {
            $paths = $this->paths;
            /** @var Application $app */
            $app = $this->app;

            // 1. Merge framework defaults + user providers (Application now
            //    exposes getDefaultProviders() publicly so no reflection needed).
            $defaults = $app->getDefaultProviders();
            $config = $this->config;
            $userProviders = $config->get('app.providers');

            // Bake discovered module providers into the cache so production
            // registers them from bootstrap.php without a per-request scan.
            $moduleProviders = (new \Nitro\Foundation\ModuleManifest(
                $paths->base('app' . DIRECTORY_SEPARATOR . 'Modules')
            ))->providers();

            $allProviders = array_merge($defaults, $userProviders, $moduleProviders);

            // NOTE: Blade directives are intentionally NOT cached here. A directive
            // callback receives the invocation's $expression; caching its output
            // for one fixed expression and replaying it for every call produced
            // wrong (or fatal — e.g. @error → `$errors[][0]`) compiled PHP. The
            // directive definitions in config/directives.php are cheap to register
            // per request and are always loaded at runtime by ViewServiceProvider.
            $cache = [
                'providers'  => $allProviders,
                'timestamp'  => time(),
            ];

            file_put_contents(
                $paths->cache('bootstrap.php'),
                "<?php\n\nreturn " . var_export($cache, true) . ";\n"
            );

            $this->output->writeln($this->output->color(
                "  ✓ Cached " . count($allProviders) . " providers",
                'green'
            ));
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color("  ✖ Bootstrap cache failed: " . $e->getMessage(), 'red'));
        }
    }

    /**
     * Introspect every table in the configured connection and dump the
     * metadata to cache/schema.php. SchemaBuilder serves from this file
     * at runtime so any app code that probes (admin panels, dynamic
     * forms, schema-aware validators) skips information_schema queries.
     *
     * Skipped gracefully when the DB isn't reachable — we don't want
     * `optimize` to fail builds in environments without DB access.
     */
    protected function cacheSchema(): void
    {
        try {
            // Bypass any existing cache while we rebuild — otherwise we'd
            // be feeding stale cache data back into the new dump.
            SchemaCache::bypass(true);
            SchemaCache::flushMemo();

            $tables = SchemaBuilder::getTables();

            // Grammars return mixed shapes (objects, arrays, strings) for
            // table listings. Normalize to plain arrays first, then pull
            // a usable name out — reset() on an object is deprecated in 8.3.
            $tableNames = [];
            foreach ($tables as $t) {
                if (is_string($t)) {
                    $row = ['name' => $t];
                } elseif (is_object($t)) {
                    $row = get_object_vars($t);
                } else {
                    $row = (array) $t;
                }
                $name = $row['table_name'] ?? $row['name'] ?? $row['Name'] ?? (reset($row) ?: null);
                if (is_string($name) && $name !== '') {
                    $tableNames[] = $name;
                }
            }
            $tableNames = array_values(array_unique($tableNames));

            $cache = [
                'tables'         => $tables,
                'table_names'    => $tableNames,
                'table_columns'  => [],
                'column_listing' => [],
                'indexes'        => [],
                'foreign_keys'   => [],
                'generated_at'   => date('c'),
            ];

            foreach ($tableNames as $name) {
                $cache['table_columns'][$name]  = $this->objectsToArrays(
                    SchemaBuilder::getColumns($name)
                );
                $cache['column_listing'][$name] = SchemaBuilder::getColumnListing($name);
                $cache['indexes'][$name]        = $this->objectsToArrays(
                    SchemaBuilder::getIndexes($name)
                );
                $cache['foreign_keys'][$name]   = $this->objectsToArrays(
                    SchemaBuilder::getForeignKeys($name)
                );
            }

            SchemaCache::bypass(false);

            $paths = $this->paths;
            file_put_contents(
                $paths->cache('schema.php'),
                "<?php\n\n// Auto-generated by `nitro optimize` — do not edit.\n\nreturn "
                    . var_export($cache, true) . ";\n"
            );

            $this->output->writeln($this->output->color(
                "  ✓ Cached schema for " . count($tableNames) . " tables",
                'green'
            ));
        } catch (\Throwable $e) {
            // No DB / bad credentials / table-less install — don't fail
            // the optimize. The schema cache simply stays absent and
            // SchemaBuilder falls through to live queries.
            SchemaCache::bypass(false);
            $this->output->writeln($this->output->color(
                "  ⚠ Schema cache skipped: " . $e->getMessage(),
                'yellow'
            ));
        }
    }

    /** Normalize DB-row objects into plain arrays so var_export emits clean PHP. */
    /**
     * Generate an opcache preload script that compiles the framework + app
     * classes into shared memory once at engine start (not per request). Enable
     * it by pointing php.ini's `opcache.preload` at the generated file.
     */
    protected function generatePreload(): void
    {
        try {
            $paths        = $this->paths;
            $preloadPath  = $paths->cache('preload.php');
            $autoload     = $paths->base('vendor/autoload.php');
            $frameworkSrc = dirname(__DIR__, 2);         // .../src of nitro/framework
            $appDir       = $paths->base('app');

            $files = $this->phpFilesIn($frameworkSrc);
            if (is_dir($appDir)) {
                $files = array_merge($files, $this->phpFilesIn($appDir));
            }

            $list = '';
            foreach ($files as $file) {
                $list .= '    ' . var_export($file, true) . ",\n";
            }

            $content  = "<?php\n\n";
            $content .= "// Auto-generated by `nitro optimize` — do not edit. `nitro optimize:clear` removes it.\n";
            $content .= "// Enable in php.ini (production):  opcache.preload={$preloadPath}\n";
            $content .= "// Compiles the framework + app classes into shared memory once at engine start,\n";
            $content .= "// so every request/worker skips autoloading and compiling them.\n\n";
            $content .= "if (! function_exists('opcache_compile_file') || ! ini_get('opcache.enable')) {\n    return;\n}\n\n";
            $content .= 'require_once ' . var_export($autoload, true) . ";\n\n";
            $content .= "\$files = [\n{$list}];\n\n";
            $content .= "foreach (\$files as \$file) {\n    @opcache_compile_file(\$file);\n}\n";

            file_put_contents($preloadPath, $content);

            $this->output->writeln($this->output->color("  ✓ Preload script generated (" . count($files) . " files)", 'green'));
            $this->output->writeln($this->output->color("    Enable it: set  opcache.preload={$preloadPath}  in php.ini", 'cyan'));
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color("  ✖ Preload generation failed: " . $e->getMessage(), 'red'));
        }
    }

    /** All *.php files under a directory, recursively and sorted. @return string[] */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Regenerate composer's autoloader as an optimized, class-authoritative
     * classmap — every class load becomes one array lookup, no per-class
     * filesystem stat. Best-effort: skips with a warning if composer isn't found.
     */
    protected function dumpAutoloader(): void
    {
        $this->runComposer(
            'dump-autoload --optimize --classmap-authoritative --no-interaction',
            '  ✓ Optimized, class-authoritative autoloader dumped',
            '  ⚠ composer dump-autoload skipped (composer not found or failed) — run it manually for an authoritative classmap'
        );
    }

    /**
     * Reset the opcache so stale compiled bytecode (e.g. an old helper bundle or
     * compiled views) isn't served. A CLI reset only touches this process; the
     * web server's opcache is cleared by reloading it — noted for the operator.
     */
    protected function resetOpcache(): void
    {
        if (function_exists('opcache_reset') && @opcache_reset()) {
            $this->output->writeln($this->output->color("  ✓ Reset opcache (CLI process)", 'green'));
        }

        $this->output->writeln($this->output->color(
            "  ⓘ Web-server opcache: reload it to drop stale bytecode — `php nitro thrust:reload` or restart PHP-FPM.",
            'cyan'
        ));
    }

    /** Run a composer subcommand in the project root; report success/skip. Non-fatal. */
    private function runComposer(string $args, string $ok, string $warn): void
    {
        $cwd = getcwd();
        @chdir($this->paths->base());
        $output = [];
        $exit   = 1;
        @exec('composer ' . $args . ' 2>&1', $output, $exit);
        @chdir($cwd);

        $this->output->writeln($this->output->color($exit === 0 ? $ok : $warn, $exit === 0 ? 'green' : 'yellow'));
    }

    private function objectsToArrays(array $rows): array
    {
        return array_map(
            static fn($r) => is_object($r) ? get_object_vars($r) : (array) $r,
            $rows,
        );
    }

    protected function clearOptimizations(): void
    {
        $this->output->writeln("");
        $this->output->writeln($this->output->color("========================================", 'yellow'));
        $this->output->writeln($this->output->color("  CLEARING OPTIMIZATIONS", 'yellow', true));
        $this->output->writeln($this->output->color("========================================", 'yellow'));
        $this->output->writeln("");

        $cleared = 0;
        $paths   = $this->paths;
        $caches  = [
            'config.php'         => 'Configuration',
            'bootstrap.php'      => 'Bootstrap',
            'views_warmup.php'   => 'View warmup bundle',
            ViewWarmup::META_FILE => 'View warmup metadata',
            'views_manifest.php' => 'View manifest',
            'schema.php'         => 'Database schema',
            'preload.php'        => 'Opcache preload script',
        ];

        foreach ($caches as $file => $name) {
            $path = $paths->cache($file);
            if (file_exists($path)) {
                unlink($path);
                $this->output->writeln($this->output->color("  ✓ Cleared {$name} cache", 'green'));
                $cleared++;
            }
        }

        // The route cache lives at cache/routes/routes.php (a subdir) and is
        // owned by RouteLoader — clear it through the loader so we target the
        // real path instead of a nonexistent cache/routes.php.
        try {
            if ($this->container->get(RouteLoader::class)->clearCache()) {
                $this->output->writeln($this->output->color("  ✓ Cleared Routes cache", 'green'));
                $cleared++;
            }
        } catch (\Throwable $e) {
            // No loader/router resolvable — nothing to clear.
        }

        $bundlePath = __DIR__ . '/../../Support/Helpers/bundle.php';
        if (is_file($bundlePath)) {
            unlink($bundlePath);
            $this->output->writeln($this->output->color("  ✓ Cleared helper bundle", 'green'));
            $cleared++;
        }

        try {
            $viewRenderer = $this->view;
            $stats        = $viewRenderer->getCacheStats();
            if ($stats['files'] > 0) {
                $viewRenderer->clearCache();
                $this->output->writeln($this->output->color("  ✓ Cleared {$stats['files']} view files", 'green'));
                $cleared++;
            }
        } catch (\Exception $e) {
            // silent fail
        }

        // Flush the runtime data cache too — what cache()->remember(...)
        // and cache()->put(...) write to. optimize:clear is the one-shot
        // "reset everything" verb, so a stale data-cache key surviving it
        // would be exactly the kind of bug operators hit and then can't
        // explain ("I cleared the cache, why is the page still wrong?").
        try {
            $cache = $this->container->get(CacheManager::class);
            if ($cache->store()->flush()) {
                $this->output->writeln($this->output->color("  ✓ Flushed runtime data cache", 'green'));
                $cleared++;
            }
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color(
                "  ⚠ Data cache flush skipped: " . $e->getMessage(),
                'yellow'
            ));
        }

        // Reset opcache and restore the non-authoritative (dev) autoloader, so a
        // fresh checkout of code / newly generated classes load again.
        $this->resetOpcache();
        $this->runComposer(
            'dump-autoload --no-interaction',
            '  ✓ Restored standard autoloader',
            '  ⚠ composer dump-autoload skipped (composer not found)'
        );

        $this->output->writeln("");
        if ($cleared > 0) {
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("  ✓ CLEARED {$cleared} CACHE TYPE(S)", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } else {
            $this->output->warning("No cache files found to clear.");
        }
        $this->output->writeln("");
    }
}
