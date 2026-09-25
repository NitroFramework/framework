<?php

namespace Nitro\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Routing\Router as BaseRouter;

/**
 * Laravel's router, used for route *registration* (so every package that type-hints
 * Illuminate\Routing\Router or uses the Route facade works unchanged).
 *
 * Matching and dispatch are delegated to Nitro\Routing\Dispatcher, which works from the
 * compiled route table instead of Symfony's matcher and the reflection-based dispatchers.
 */
class Router extends BaseRouter
{
    protected ?Dispatcher $nitroDispatcher = null;

    public function newRoute($methods, $uri, $action)
    {
        return (new Route($methods, $uri, $action))
            ->setRouter($this)
            ->setContainer($this->container);
    }

    public function useCompiledRoutes(CompiledRoutes $routes): void
    {
        $this->routes = $routes;

        if ($this->container->resolved('url')) {
            $this->container->instance('routes', $routes);
        }
    }

    /**
     * The compiled table for dispatch. Uncached apps compile in memory on first use.
     */
    public function compiledRoutes(): CompiledRoutes
    {
        if (! $this->routes instanceof CompiledRoutes) {
            $this->useCompiledRoutes(CompiledRoutes::fromCollection($this->routes, $this));
        }

        return $this->routes;
    }

    public function setCurrentRoute(\Illuminate\Routing\Route $route, Request $request): void
    {
        $this->current = $route;
        $this->currentRequest = $request;
    }

    /** The container routes are currently resolved from (Octane swaps it per request). */
    public function container(): \Illuminate\Container\Container
    {
        return $this->container;
    }

    public function nitroDispatcher(): Dispatcher
    {
        return $this->nitroDispatcher ??= new Dispatcher($this->container, $this);
    }

    public function dispatch(Request $request)
    {
        $this->currentRequest = $request;

        return $this->nitroDispatcher()->dispatch($request);
    }

    public function dispatchToRoute(Request $request)
    {
        return $this->dispatch($request);
    }

    /**
     * Implicit model / backed-enum binding from the compiled signature map (no reflection).
     * Mirrors Illuminate\Routing\ImplicitRouteBinding::resolveForRoute().
     */
    public function substituteImplicitBindings($route)
    {
        $bindings = $route instanceof Route && $route->nitroIndex !== null && $this->routes instanceof CompiledRoutes
            ? $this->routes->implicitBindings($route->nitroIndex)
            : null;

        $default = $bindings === null
            ? fn () => ImplicitRouteBinding::resolveForRoute($this->container, $route)
            : fn () => ImplicitBinder::resolve($this->container, $route, $bindings);

        return call_user_func($this->implicitBindingCallback ?? $default, $this->container, $route, $default);
    }

    public function getMiddlewarePriority(): array
    {
        return $this->middlewarePriority;
    }
}
