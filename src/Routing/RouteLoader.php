<?php

namespace Nitro\Routing;

use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Throwable;

/**
 * Loads routes into the router and manages the compiled route cache.
 *
 * In production, routes are restored from a pre-compiled cache file for speed;
 * in debug mode (or when no cache exists) they are loaded by requiring the
 * routes file. Also responsible for writing and clearing that cache.
 */
class RouteLoader
{
    /** @var array<int, array{path: string, prefix: string}> Route files to load, in order. */
    private array $routeFiles;
    private string $cacheFile;
    private bool $useCache;

    /**
     * Resolve the route files and cache file locations, and decide whether to
     * use the cache based on the app's debug flag.
     *
     * Laravel layout first: routes/web.php (no prefix) + routes/api.php (under
     * /api). Falls back to the legacy single config/routes.php when neither
     * exists, so older apps keep working.
     */
    public function __construct(PathRegistry $paths, ConfigRepository $config)
    {
        $web = $paths->base('routes' . DIRECTORY_SEPARATOR . 'web.php');
        $api = $paths->base('routes' . DIRECTORY_SEPARATOR . 'api.php');

        $files = [];
        if (file_exists($web)) {
            $files[] = ['path' => $web, 'prefix' => ''];
        }
        if (file_exists($api)) {
            $files[] = ['path' => $api, 'prefix' => 'api'];
        }
        if ($files === []) {
            $legacy = $paths->config('routes.php');
            if (file_exists($legacy)) {
                $files[] = ['path' => $legacy, 'prefix' => ''];
            }
        }

        $this->routeFiles = $files;
        $this->cacheFile = $paths->cache('routes' . DIRECTORY_SEPARATOR . 'routes.php');
        $this->useCache = !$config->get('app.debug');
    }

    /** Whether any route file was found. */
    public function hasRouteFiles(): bool
    {
        return $this->routeFiles !== [];
    }

    /**
     * Append an additional route file to load, e.g. from a module provider.
     *
     * Registered during provider register() (before RoutingServiceProvider::boot
     * calls load()), so module routes are picked up in dev and baked into the
     * compiled route cache by `nitro optimize`. Missing files are ignored so a
     * module without a routes.php is a no-op rather than a fatal require.
     *
     * @param string $path   Absolute path to the routes definition file.
     * @param string $prefix Optional URI prefix to mount the file's routes under.
     */
    public function addRouteFile(string $path, string $prefix = ''): void
    {
        if (is_file($path)) {
            $this->routeFiles[] = ['path' => $path, 'prefix' => $prefix];
        }
    }

    /**
     * Load routes into the router, preferring the compiled cache when enabled
     * and present, otherwise falling back to the routes file.
     */
    public function load(RouterInterface $router): void
    {
        if ($this->useCache && file_exists($this->cacheFile) && $this->cacheIsFresh()) {
            $this->loadFromCache($router);
        } else {
            $this->loadFromFile($router);
        }
    }

    /**
     * Is the compiled route cache still fresh relative to its source files? A
     * route file edited after the cache was built makes it stale; we then load
     * from source instead of serving outdated routes. (In debug mode the cache
     * is skipped entirely; this guards the production path.)
     */
    private function cacheIsFresh(): bool
    {
        $cacheTime = @filemtime($this->cacheFile);
        if ($cacheTime === false) {
            return false;
        }
        foreach ($this->routeFiles as $file) {
            $sourceTime = @filemtime($file['path']);
            if ($sourceTime !== false && $sourceTime > $cacheTime) {
                return false;
            }
        }
        return true;
    }

    /**
     * Load routes by requiring the routes definition file, if it exists.
     *
     * The require runs inside a closure so the routes file sees only the
     * $router variable and cannot leak locals into this method's scope.
     */
    public function loadFromFile(RouterInterface $router): void
    {
        foreach ($this->routeFiles as $file) {
            $load = function () use ($router, $file) {
                require $file['path'];
            };

            // Apply the matching middleware group so web routes get the 'web'
            // stack (CSRF, …) and api routes get 'api' — Laravel's convention.
            // The Kernel expands these group names into their members at match
            // time. A prefix (api.php → /api) is applied in the same group.
            $attributes = $file['prefix'] !== ''
                ? ['prefix' => $file['prefix'], 'middleware' => [$file['prefix']]]
                : ['middleware' => ['web']];

            $router->group($attributes, function () use ($load) {
                $load();
            });
        }
    }

    /**
     * Restore routes from the compiled cache file, falling back to loading the
     * routes file if the cache is malformed or unreadable.
     */
    private function loadFromCache(RouterInterface $router): void
    {
        try {
            $cached = require $this->cacheFile;
            if (isset($cached['routes']) && is_array($cached['routes'])) {
                $router->loadCachedRoutes($cached);
            }
        } catch (Throwable $e) {
            $this->loadFromFile($router);
        }
    }

    /**
     * Compile the router's current routes to a cache file.
     *
     * Route caching is all-or-nothing. The compiled static/dynamic tables can
     * carry Closure handlers, which var_export cannot emit (it writes
     * `\Closure::__set_state`, which fatals on require). Rather than ship a
     * corrupt cache that fatals-then-silently-falls-back every request, we
     * refuse to write when any closure route is present.
     *
     * @return int 0 when the cache was written; otherwise the number of closure
     *   routes that blocked caching (convert them to controller actions).
     */
    public function cache(RouterInterface $router): int
    {
        $routes = $router->getRoutes();
        $namedRoutes = $router->getNamedRoutes();
        $compiled = $router->getCompiledRoutes();
        $cacheableRoutes = [];

        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $handler) {
                if (isset($handler['type']) && $handler['type'] !== 'closure') {
                    $cacheableRoutes[$method][$path] = $handler;
                }
            }
        }

        $cacheData = [
            'routes'            => $cacheableRoutes,
            'named_routes'      => $namedRoutes,
            'static_routes'     => $compiled['static'],
            'dynamic_routes'    => $compiled['dynamic'],
            'dynamic_by_prefix' => $compiled['byPrefix'],
            'compiled_patterns' => $compiled['patterns'],
            'cached_at'         => time(),
            'generator_version' => '2.3',
        ];

        if ($this->containsClosure($cacheData)) {
            // Don't leave a stale/corrupt cache behind.
            if (file_exists($this->cacheFile)) {
                @unlink($this->cacheFile);
            }
            return $this->countClosureRoutes($routes);
        }

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $content = "<?php\n\nreturn " . var_export($cacheData, true) . ";\n";
        file_put_contents($this->cacheFile, $content, LOCK_EX);

        return 0;
    }

    /** Recursively detect a Closure anywhere in the cache payload. */
    private function containsClosure(mixed $value): bool
    {
        if ($value instanceof \Closure) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsClosure($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Count routes registered with a closure handler (for operator messaging). */
    private function countClosureRoutes(array $routes): int
    {
        $count = 0;
        foreach ($routes as $methodRoutes) {
            foreach ($methodRoutes as $handler) {
                if (($handler['type'] ?? null) === 'closure') {
                    $count++;
                }
            }
        }
        return max($count, 1);
    }

    /**
     * Delete the compiled route cache file and, when a router is given, reset
     * its in-memory routes.
     *
     * @return bool True when a cache file existed and was removed.
     */
    public function clearCache(?RouterInterface $router = null): bool
    {
        $cleared = false;
        if (file_exists($this->cacheFile)) {
            $cleared = @unlink($this->cacheFile);
        }

        if ($router) {
            $router->clearRoutes();
        }

        return $cleared;
    }
}
