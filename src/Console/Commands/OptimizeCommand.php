<?php

namespace Nitro\Console\Commands;

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
use Nitro\View\Compiler\BladeCompiler;

/**
 * Console commands: build (optimize) and clear (optimize:clear) the production caches — config, routes, views, providers, schema.
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

        $this->output->info("Step 1/6: Caching configuration...");
        $this->cacheConfig();

        $this->output->info("Step 2/6: Caching routes...");
        $this->cacheRoutes();

        $this->output->info("Step 3/6: Caching views...");
        $this->cacheViews();

        $this->output->info("Step 4/6: Caching service providers...");
        $this->cacheBootstrap();

        $this->output->info("Step 5/6: Bundling helpers...");
        $this->bundleHelpers();

        $this->output->info("Step 6/6: Caching database schema...");
        $this->cacheSchema();

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
            $data         = $this->filterSerializable($config->all());
            $cacheContent = "<?php\n\nreturn " . var_export($data, true) . ";\n";
            file_put_contents($paths->cache('config.php'), $cacheContent);
            $this->output->writeln($this->output->color("  ✓ Configuration cached", 'green'));
        } catch (\Exception $e) {
            $this->output->writeln($this->output->color("  ✖ Config cache failed: " . $e->getMessage(), 'red'));
        }
    }

    protected function filterSerializable(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \Closure || is_object($value)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSerializable($value);
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
            $routeLoader->cache($router);
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
            // Same dependency-sensitive order as Support/helpers.php fallback.
            $files = [
                'app.php', 'config.php', 'path.php', 'array.php', 'collection.php',
                'conditional.php', 'debug.php', 'file.php', 'http.php', 'request.php',
                'response.php', 'security.php', 'auth.php', 'session.php', 'string.php',
                'url.php', 'utility.php', 'validation.php', 'view.php', 'query.php',
                'cache.php',
            ];

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

            // 2. Pre-compile custom Blade directives so the runtime can
            //    hydrate them directly without re-loading config/directives.php
            //    per request. The optimize CLI is the only place we evaluate
            //    that file so callbacks can be captured.
            $directives = $this->captureDirectives($paths);

            $cache = [
                'providers'  => $allProviders,
                'directives' => $directives,
                'timestamp'  => time(),
            ];

            file_put_contents(
                $paths->cache('bootstrap.php'),
                "<?php\n\nreturn " . var_export($cache, true) . ";\n"
            );

            $this->output->writeln($this->output->color(
                "  ✓ Cached " . count($allProviders) . " providers, "
                . count($directives) . " directives",
                'green'
            ));
        } catch (\Throwable $e) {
            $this->output->writeln($this->output->color("  ✖ Bootstrap cache failed: " . $e->getMessage(), 'red'));
        }
    }

    /**
     * Capture every currently-registered Blade directive's compiled output
     * for an empty expression. Most framework directives (@elapsed_time,
     * @memory_usage, etc.) don't use their $expression argument, so this
     * snapshot is correct for them. Directives that DO need their argument
     * raise an exception on call — those are skipped here and will register
     * normally at runtime via Blade::directive().
     *
     * By the time `nitro optimize` reaches this step, ViewServiceProvider::boot
     * has already run and registered every directive defined in
     * config/directives.php, so we read directly from BladeCompiler.
     */
    protected function captureDirectives(PathRegistry $paths): array
    {
        $registered = BladeCompiler::getCustomDirectives();
        $compiled = [];

        foreach ($registered as $name => $callback) {
            try {
                $php = $callback('');
                if (is_string($php) && $php !== '') {
                    $compiled[$name] = $php;
                }
            } catch (\Throwable) {
                // Skip — the directive needs a real expression and will be
                // re-registered at runtime via the live directives.php path.
            }
        }

        return $compiled;
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
            'routes.php'         => 'Routes',
            'bootstrap.php'      => 'Bootstrap',
            'views_warmup.php'   => 'View warmup bundle',
            ViewWarmup::META_FILE => 'View warmup metadata',
            'views_manifest.php' => 'View manifest',
            'schema.php'         => 'Database schema',
        ];

        foreach ($caches as $file => $name) {
            $path = $paths->cache($file);
            if (file_exists($path)) {
                unlink($path);
                $this->output->writeln($this->output->color("  ✓ Cleared {$name} cache", 'green'));
                $cleared++;
            }
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
