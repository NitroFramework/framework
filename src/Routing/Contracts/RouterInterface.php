<?php

namespace Nitro\Routing\Contracts;

use Nitro\Http\Request;
use Nitro\Routing\Route;

/**
 * Contract for the NitroPHP router.
 *
 * Defines route registration (HTTP verbs, groups, views), request matching,
 * and the introspection/cache hooks the framework relies on to compile and
 * restore routes.
 */
interface RouterInterface
{
    /**
     * Register a route that responds to GET requests.
     */
    public function get(string $path, $handler): static;

    /**
     * Register a route that responds to POST requests.
     */
    public function post(string $path, $handler): static;

    /**
     * Register a route that responds to PUT requests.
     */
    public function put(string $path, $handler): static;

    /**
     * Register a route that responds to DELETE requests.
     */
    public function delete(string $path, $handler): static;

    /**
     * Register a route that responds to PATCH requests.
     */
    public function patch(string $path, $handler): static;

    /**
     * Register a group of routes sharing prefix, middleware, namespace
     * and/or name attributes.
     */
    public function group(array $attributes, \Closure $callback): static;

    /**
     * Register a GET route that renders a view with the given data.
     */
    public function view(string $path, string $viewName, array $data = []): static;

    /**
     * Match an incoming request to a route, returning the resolved {@see Route}
     * or null when nothing matches.
     */
    public function findMatchingRoute(Request $request): ?Route;

    /**
     * Return all registered routes keyed by HTTP method and path.
     */
    public function getRoutes(): array;

    /**
     * Return the map of named routes to their paths.
     */
    public function getNamedRoutes(): array;

    /**
     * Return the pre-compiled route structures (static, dynamic, patterns)
     * used for fast matching and caching.
     */
    public function getCompiledRoutes(): array;

    /**
     * Hydrate the router from a previously cached route payload.
     */
    public function loadCachedRoutes(array $cached): void;

    /**
     * Remove all registered and compiled routes from the router.
     */
    public function clearRoutes(): void;
}
