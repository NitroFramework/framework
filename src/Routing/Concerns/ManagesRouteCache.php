<?php

namespace Nitro\Routing\Concerns;

/**
 * Route cache (de)serialization for the {@see \Nitro\Routing\Router}.
 *
 * Exposes the compiled lookup structures for caching, restores them from a
 * cached payload (supporting both the legacy flat format and the current
 * optimized format), and clears all route state.
 */
trait ManagesRouteCache
{
    /**
     * Turns one cached route's stored form back into a usable one, or null
     * when routes came from source and nothing needs restoring.
     *
     * @var \Closure(array<string, mixed>): array<string, mixed>|null
     */
    private ?\Closure $handlerRestorer = null;

    /**
     * Install the callback that restores a cached route when it is matched.
     *
     * A cached closure handler costs real time to rebuild — PHP has to compile
     * the stored source — so the payload keeps handlers in their stored form
     * and this runs for the one route a request actually matches, rather than
     * for all of them at load. Passing null turns restoration off again.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed>|null $restorer
     */
    public function restoreHandlersUsing(?\Closure $restorer): void
    {
        $this->handlerRestorer = $restorer;
    }

    /**
     * One stored route, in the form the router can build a {@see \Nitro\Routing\Route} from.
     *
     * @param  array<string, mixed> $routeData
     * @return array<string, mixed>
     */
    protected function restoreRouteData(array $routeData): array
    {
        return $this->handlerRestorer === null
            ? $routeData
            : ($this->handlerRestorer)($routeData);
    }

    /**
     * Return the pre-compiled lookup structures (static map, dynamic list and
     * compiled regex patterns) for persisting to the route cache.
     */
    public function getCompiledRoutes(): array
    {
        return [
            'static' => $this->staticRoutes,
            'dynamic' => $this->dynamicRoutes,
            'byPrefix' => $this->dynamicRoutesByPrefix,
            'patterns' => $this->compiledPatterns,
        ];
    }

    /**
     * Hydrate the router from a cached payload.
     *
     * The current format ships the optimized structures directly; the legacy
     * format ships only the flat route map, from which the optimized
     * structures are rebuilt. Extra compiled data, when supplied, overrides
     * the corresponding structures.
     */
    public function loadCachedRoutes(array $routes, array $compiledRoutes = []): void
    {
        // Support both old and new cache formats
        if (isset($routes['routes'])) {
            // New format with optimized structures
            $this->routes = $routes['routes'];
            $this->namedRoutes = $routes['named_routes'] ?? [];
            $this->staticRoutes = $routes['static_routes'] ?? [];
            $this->dynamicRoutes = $routes['dynamic_routes'] ?? [];
            $this->compiledPatterns = $routes['compiled_patterns'] ?? [];
            // Prefix buckets drive matching; restore them so the cached path
            // gets the same bucketed scan as live registration. Older caches
            // that predate this key rebuild the buckets from the flat list.
            $this->dynamicRoutesByPrefix = $routes['dynamic_by_prefix']
                ?? $this->rebuildPrefixBuckets();
        } else {
            // Old format - rebuild optimized structures
            $this->routes = $routes;
            $this->rebuildOptimizedStructures();
        }

        // Load additional compiled data if provided
        if (!empty($compiledRoutes)) {
            $this->staticRoutes = $compiledRoutes['static'] ?? $this->staticRoutes;
            $this->dynamicRoutes = $compiledRoutes['dynamic'] ?? $this->dynamicRoutes;
            $this->dynamicRoutesByPrefix = $compiledRoutes['byPrefix'] ?? $this->dynamicRoutesByPrefix;
            $this->compiledPatterns = $compiledRoutes['patterns'] ?? $this->compiledPatterns;
        }
    }

    /**
     * Rebuild the static/dynamic/pattern structures from the flat route map,
     * used when loading the legacy cache format that lacks them.
     */
    protected function rebuildOptimizedStructures(): void
    {
        $this->staticRoutes = [];
        $this->dynamicRoutes = [];
        $this->dynamicRoutesByPrefix = [];
        $this->compiledPatterns = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $path => $handler) {
                if ($this->hasParameters($path)) {
                    // Dynamic route
                    if (!isset($this->dynamicRoutes[$method])) {
                        $this->dynamicRoutes[$method] = [];
                    }
                    $route = [
                        'pattern' => $path,
                        'handler' => $handler,
                        'param_names' => $this->extractParameterNames($path)
                    ];
                    $this->dynamicRoutes[$method][] = $route;
                    $this->dynamicRoutesByPrefix[$method][$this->prefixBucket($path)][] = $route;
                    $this->compiledPatterns[$method][$path] = $this->compilePattern($path);
                } else {
                    // Static route
                    if (!isset($this->staticRoutes[$method])) {
                        $this->staticRoutes[$method] = [];
                    }
                    $this->staticRoutes[$method][$path] = $handler;
                }
            }
        }
    }

    /**
     * Rebuild only the prefix buckets from the already-populated flat dynamic
     * list. Used when restoring a cache that predates the persisted buckets.
     */
    protected function rebuildPrefixBuckets(): array
    {
        $buckets = [];
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $route) {
                $buckets[$method][$this->prefixBucket($route['pattern'])][] = $route;
            }
        }
        return $buckets;
    }

    /**
     * Reset the router, discarding all registered routes and the compiled
     * lookup structures.
     */
    public function clearRoutes(): void
    {
        $this->routes = [];
        $this->staticRoutes = [];
        $this->dynamicRoutes = [];
        $this->dynamicRoutesByPrefix = [];
        $this->compiledPatterns = [];
        $this->namedRoutes = [];
    }
}
