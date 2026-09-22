<?php

namespace Nitro\Routing;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use ReflectionFunction;
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
    /** @var array<int, array{path: string, attributes: array<string, mixed>}> Route files to load, in order. */
    private array $routeFiles;
    private string $cacheFile;
    private bool $useCache;
    private ClassResolver $resolver;

    /**
     * Resolve the route files and cache file locations, and decide whether to
     * use the cache based on the app's debug flag.
     *
     * routes/web.php gets the `web` stack; routes/api.php gets the `api` stack
     * and sits under /api. The two are stated independently: the URI prefix is
     * not what names the middleware group, so moving api.php's prefix would not
     * send it looking for a group that does not exist.
     *
     * Falls back to the legacy single config/routes.php when neither exists,
     * so older apps keep working.
     */
    public function __construct(PathRegistry $paths, ConfigRepository $config, ClassResolver $resolver)
    {
        $this->resolver = $resolver;

        $web = $paths->base('routes' . DIRECTORY_SEPARATOR . 'web.php');
        $api = $paths->base('routes' . DIRECTORY_SEPARATOR . 'api.php');

        $files = [];
        if (file_exists($web)) {
            $files[] = ['path' => $web, 'attributes' => ['middleware' => ['web']]];
        }
        if (file_exists($api)) {
            $files[] = ['path' => $api, 'attributes' => ['middleware' => ['api'], 'prefix' => 'api']];
        }
        if ($files === []) {
            $legacy = $paths->config('routes.php');
            if (file_exists($legacy)) {
                $files[] = ['path' => $legacy, 'attributes' => ['middleware' => ['web']]];
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
     * Append an additional route file to load, e.g. from a module or package
     * provider.
     *
     * The file gets the `web` stack and nothing else. A package that wants a
     * URI prefix, a different middleware stack or a route-name prefix declares
     * its own `Route::group()` inside the file — the same way a package does
     * it in Laravel, and the reason this method takes only a path.
     *
     * Registered during provider register() (before RoutingServiceProvider::boot
     * calls load()), so module routes are picked up in dev and baked into the
     * compiled route cache by `nitro optimize`. Missing files are ignored so a
     * module without a routes.php is a no-op rather than a fatal require.
     *
     * @param string $path Absolute path to the routes definition file.
     */
    public function addRouteFile(string $path): void
    {
        if (is_file($path)) {
            $this->routeFiles[] = ['path' => $path, 'attributes' => ['middleware' => ['web']]];
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

            // Each file carries the group it loads under, decided where the
            // file was registered. The Kernel expands a group name into its
            // members at match time.
            $router->group($file['attributes'], function () use ($load) {
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
                /*
                 * Handlers stay in their stored form. Rebuilding a closure
                 * means compiling its source, which is far dearer than the
                 * table lookup the cache exists to save, and a request needs
                 * exactly one of them — so the router restores the route it
                 * matched and leaves the other hundred alone.
                 *
                 * Restoring on match is not part of the router contract, which
                 * stays small enough for an application to supply its own. One
                 * that does not offer it is handed a fully restored payload
                 * instead: correct either way, and only the closure-heavy case
                 * pays for it.
                 */
                if (method_exists($router, 'restoreHandlersUsing')) {
                    $router->loadCachedRoutes($cached);
                    $router->restoreHandlersUsing(
                        fn (array $routeData): array => $this->restoreRoute($routeData)
                    );
                } else {
                    $router->loadCachedRoutes($this->restoreFromCache($cached));
                }
            }
        } catch (Throwable $exception) {
            $this->loadFromFile($router);
        }
    }

    /**
     * Compile the router's current routes to a cache file.
     *
     * Closure handlers are serialized on the way in, since var_export cannot
     * emit them (it writes `\Closure::__set_state`, which fatals on require).
     * A handler that still will not serialize leaves a live Closure in the
     * payload; caching is all-or-nothing, so one of those means no cache at
     * all rather than a partial one that answers 404 for whatever it dropped.
     *
     * @return int 0 when the cache was written; otherwise the number of routes
     *   whose handler could not be serialized, named by uncacheableRoutes().
     */
    public function cache(RouterInterface $router): int
    {
        $routes = $router->getRoutes();
        $compiled = $router->getCompiledRoutes();

        $cacheData = [
            'routes'            => $routes,
            'named_routes'      => $router->getNamedRoutes(),
            'static_routes'     => $compiled['static'],
            'dynamic_routes'    => $compiled['dynamic'],
            'dynamic_by_prefix' => $compiled['byPrefix'],
            'compiled_patterns' => $compiled['patterns'],
            'cached_at'         => time(),
            'generator_version' => '2.4',
        ];

        $prepared = $this->prepareArrayForCache($cacheData);

        /*
         * Inspected per route so a handler that would not serialize can be
         * named. The compiled tables hold the same handlers as 'routes', so
         * walking that one branch finds every offender.
         */
        $this->uncacheable = [];

        $preparedRoutes = is_array($prepared['routes']) ? $prepared['routes'] : [];

        foreach ($preparedRoutes as $method => $methodRoutes) {
            foreach ((array) $methodRoutes as $path => $handler) {
                if ($this->containsClosure($handler)) {
                    $this->uncacheable[] = $method . ' ' . $path;
                }
            }
        }

        if ($this->uncacheable !== []) {
            if (file_exists($this->cacheFile)) {
                @unlink($this->cacheFile);
            }

            return count($this->uncacheable);
        }

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $content = "<?php\n\nreturn " . var_export($prepared, true) . ";\n";
        file_put_contents($this->cacheFile, $content, LOCK_EX);

        return 0;
    }

    /** The marker a serialized closure is stored under. */
    private const CLOSURE_KEY = '__nitro_closure';

    /** The marker a detached framework service is stored under. */
    private const SERVICE_KEY = '__nitro_service';

    /** The marker the object a closure was bound to is stored under. */
    private const BINDING_KEY = '__nitro_binding';

    /**
     * Services replaced by a name on the way into the cache and resolved again
     * on the way out.
     *
     * A route closure written inside a service provider routinely captures the
     * container, or is bound to the router that is registering it. Serializing
     * those verbatim would walk the whole object graph — and since the router
     * holds every other route handler, one closure would drag in all of them
     * and hit a raw Closure that cannot be serialized. Storing the name and
     * resolving it again at load also keeps the restored closure pointed at
     * the live service rather than a stale copy of the one that was cached.
     *
     * @var array<int, class-string>
     */
    private const DETACHABLE = [
        ContainerInterface::class,
        RouterInterface::class,
    ];

    /**
     * Routes whose handler could not be serialized, as "METHOD /path".
     *
     * @var array<int, string>
     */
    private array $uncacheable = [];

    /**
     * Which routes stopped the cache being written, after cache() has run.
     *
     * @return array<int, string>
     */
    public function uncacheableRoutes(): array
    {
        return $this->uncacheable;
    }

    /**
     * Replace every closure in the payload with something var_export can write.
     *
     * A closure that cannot be serialized — one bound to an object holding a
     * resource, say — is left as it is, so the caller's check still sees it
     * and refuses the cache rather than writing one that is quietly missing a
     * route.
     */
    private function prepareForCache(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return $this->prepareClosure($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return $this->prepareArrayForCache($value);
    }

    /**
     * prepareForCache() over an array, keeping the array type through the
     * call so the compiled payload can be indexed afterwards.
     *
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function prepareArrayForCache(array $value): array
    {
        return array_map(fn (mixed $item): mixed => $this->prepareForCache($item), $value);
    }

    /**
     * Serialize one closure, detaching the framework services it holds.
     *
     * Returns the closure untouched when it still will not serialize, so the
     * caller's check sees it and refuses the cache rather than writing one
     * that is quietly missing a route.
     *
     * @return array{__nitro_closure: string, __nitro_binding?: array{service: class-string, scope: ?string}}|Closure
     */
    private function prepareClosure(Closure $closure): array|Closure
    {
        $reflection = new ReflectionFunction($closure);
        $scope = $reflection->getClosureScopeClass()?->getName();
        $binding = $this->detachableService($reflection->getClosureThis());
        $subject = $closure;

        if ($binding !== null) {
            /*
             * A closure whose body reads $this cannot be unbound; PHP warns
             * and hands back null. That one keeps its binding and will be
             * reported as uncacheable instead.
             */
            $unbound = @Closure::bind($closure, null, $scope);

            if ($unbound instanceof Closure) {
                $subject = $unbound;
            } else {
                $binding = null;
            }
        }

        try {
            $payload = $this->withServicesDetached(
                static fn (): string => serialize(SerializableClosure::unsigned($subject))
            );
        } catch (Throwable) {
            return $closure;
        }

        $entry = [self::CLOSURE_KEY => $payload];

        if ($binding !== null) {
            $entry[self::BINDING_KEY] = ['service' => $binding, 'scope' => $scope];
        }

        return $entry;
    }

    /**
     * The contract naming a captured value, when it is a service the cache
     * should store by name rather than by value.
     *
     * @return class-string|null
     */
    private function detachableService(mixed $value): ?string
    {
        if (! is_object($value)) {
            return null;
        }

        foreach (self::DETACHABLE as $service) {
            if ($value instanceof $service) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Run a serialization with captured framework services swapped for their
     * names.
     *
     * The hook is a static on the serializer, so whatever was installed before
     * is put back afterwards rather than being cleared.
     */
    private function withServicesDetached(callable $callback): mixed
    {
        $previous = Native::$transformUseVariables;

        SerializableClosure::transformUseVariablesUsing(function (array $use): array {
            foreach ($use as $name => $captured) {
                $service = $this->detachableService($captured);

                if ($service !== null) {
                    $use[$name] = [self::SERVICE_KEY => $service];
                }
            }

            return $use;
        });

        try {
            return $callback();
        } finally {
            SerializableClosure::transformUseVariablesUsing($previous);
        }
    }

    /** Run an unserialization with detached service names resolved again. */
    private function withServicesAttached(callable $callback): mixed
    {
        $previous = Native::$resolveUseVariables;

        SerializableClosure::resolveUseVariablesUsing(function (array $use): array {
            foreach ($use as $name => $captured) {
                if (is_array($captured) && isset($captured[self::SERVICE_KEY]) && is_string($captured[self::SERVICE_KEY])) {
                    $use[$name] = $this->resolver->resolve($captured[self::SERVICE_KEY]);
                }
            }

            return $use;
        });

        try {
            return $callback();
        } finally {
            SerializableClosure::resolveUseVariablesUsing($previous);
        }
    }

    /**
     * One matched route, with any stored handler turned back into a closure.
     *
     * Restoration is memoised on the serialized payload: a route matched
     * again — every request in worker mode — rebuilds nothing, and two routes
     * sharing a handler rebuild it once.
     *
     * @param  array<string, mixed> $routeData
     * @return array<string, mixed>
     */
    private function restoreRoute(array $routeData): array
    {
        $handler = $routeData['handler'] ?? null;

        if (! is_array($handler) || ! isset($handler[self::CLOSURE_KEY]) || ! is_string($handler[self::CLOSURE_KEY])) {
            return $routeData;
        }

        $key = $handler[self::CLOSURE_KEY];

        $routeData['handler'] = $this->restored[$key] ??= $this->restoreClosure($handler);

        return $routeData;
    }

    /**
     * Closures already rebuilt this process, keyed by their stored payload.
     *
     * @var array<string, mixed>
     */
    private array $restored = [];

    /**
     * Turn the markers written by prepareForCache() back into closures.
     *
     * @param array<array-key, mixed> $value
     */
    private function restoreFromCache(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value[self::CLOSURE_KEY]) && is_string($value[self::CLOSURE_KEY])) {
            return $this->restoreClosure($value);
        }

        return array_map(fn (mixed $item): mixed => $this->restoreFromCache($item), $value);
    }

    /**
     * Rebuild one closure from its cache entry, resolving the services that
     * were detached before it was written.
     *
     * @param array<array-key, mixed> $entry
     */
    private function restoreClosure(array $entry): mixed
    {
        $payload = $entry[self::CLOSURE_KEY];

        $restored = $this->withServicesAttached(
            static fn (): mixed => unserialize(is_string($payload) ? $payload : '')
        );

        /*
         * Asked by capability rather than by class: unsigned() hands back an
         * UnsignedSerializableClosure, which is a different type to the signed
         * one and shares no parent, so naming either misses half the cases.
         */
        $closure = is_object($restored) && method_exists($restored, 'getClosure')
            ? $restored->getClosure()
            : $restored;

        $binding = $entry[self::BINDING_KEY] ?? null;

        if ($closure instanceof Closure && is_array($binding) && isset($binding['service']) && is_string($binding['service'])) {
            $scope = $binding['scope'] ?? null;

            $closure = $closure->bindTo(
                $this->resolver->resolve($binding['service']),
                is_string($scope) ? $scope : null
            ) ?? $closure;
        }

        return $closure;
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
