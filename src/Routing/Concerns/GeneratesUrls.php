<?php

namespace Nitro\Routing\Concerns;

use InvalidArgumentException;
use RuntimeException;

/**
 * Named-route registration and URL generation for the
 * {@see \Nitro\Routing\Router}.
 *
 * Lets a freshly defined route be given a name, keeps a registry of named
 * routes, and builds concrete URLs by substituting parameters back into a
 * named route's path.
 */
trait GeneratesUrls
{
    /** Named routes registry */
    protected array $namedRoutes = [];

    /** Last registered route reference for chaining */
    protected ?array $lastRoute = null;

    /**
     * Assign a name to the most recently registered route (prefixed by the
     * current group name) and record it in the named-route registry.
     *
     * @throws RuntimeException When called before any route has been defined.
     */
    public function name(string $name): static
    {
        if (!$this->lastRoute) {
            throw new RuntimeException('No route to name. Call name() immediately after defining a route.');
        }

        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];

        // Build full name with current group prefix
        $fullName = $this->currentName . $name;

        // Set the name on the route in EVERY storage location. Dynamic routes
        // are denormalized into three structures (unified map, flat dynamic
        // list, and the prefix buckets that matching actually reads), each a
        // by-value copy — miss one and the matched Route loses its name in that
        // code path. See storeRoute()/findDynamicRoute().
        $this->routes[$method][$path]['name'] = $fullName;

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path]['name'] = $fullName;
        } else {
            // Flat dynamic list (used as the cache source and match fallback).
            if (isset($this->dynamicRoutes[$method])) {
                foreach ($this->dynamicRoutes[$method] as &$route) {
                    if ($route['pattern'] === $path) {
                        $route['handler']['name'] = $fullName;
                        break;
                    }
                }
                unset($route);
            }

            // Prefix buckets — the structure findDynamicRoute() scans live.
            if (isset($this->dynamicRoutesByPrefix[$method])) {
                foreach ($this->dynamicRoutesByPrefix[$method] as &$bucket) {
                    foreach ($bucket as &$route) {
                        if ($route['pattern'] === $path) {
                            $route['handler']['name'] = $fullName;
                            break;
                        }
                    }
                    unset($route);
                }
                unset($bucket);
            }
        }

        // Store in named routes registry
        $this->namedRoutes[$fullName] = [
            'method' => $method,
            'path' => $path,
        ];

        return $this;
    }

    /**
     * Look up a named route's stored method/path, or null if unknown.
     */
    public function getRouteByName(string $name): ?array
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route by substituting the given parameters
     * into its path.
     *
     * @throws InvalidArgumentException When the route is unknown or required
     *         parameters are missing.
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->getRouteByName($name);

        if (!$route) {
            throw new InvalidArgumentException("Route [{$name}] not found");
        }

        $path = $route['path'];

        // Replace parameters in path
        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        // Check for unreplaced parameters
        if (preg_match('/\{[^}]+\}/', $path)) {
            throw new InvalidArgumentException("Missing parameters for route [{$name}]");
        }

        return $path;
    }

    /**
     * Return the full named-route registry keyed by route name.
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }
}
