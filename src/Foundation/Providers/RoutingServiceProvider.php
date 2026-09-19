<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Container;
use Nitro\Database\Model\Model;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\PreventRequestsDuringMaintenance;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;

/**
 * Registers the router, route loader, dispatcher and route-model binding.
 */
class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
       $this->container->singleton(Router::class, Router::class);

        $this->container->singleton(RouteLoader::class, RouteLoader::class);

       $this->container->singleton(RouteDispatcher::class, RouteDispatcher::class);

        $this->container->alias(RouterInterface::class, Router::class);
        $this->container->alias('router', Router::class);
        $this->container->alias('routeLoader', RouteLoader::class);

        $this->registerRouteModelBinding();
    }

    /**
     * Implicit route-model binding: a controller/closure parameter type-hinted
     * as a model whose name matches a route segment (e.g. /users/{user} →
     * show(User $user)) is resolved via Model::find(), 404-ing when missing.
     *
     * Registered as a container parameter binder so the core stays unaware of
     * the Database/HTTP layers — the policy lives here, in the composition root.
     */
    protected function registerRouteModelBinding(): void
    {
        $this->container->bindParametersUsing(function (string $type, mixed $value) {
            if (!is_subclass_of($type, Model::class)) {
                return Container::PARAM_UNRESOLVED;
            }

            $model = $type::find($value);

            if ($model === null) {
                throw new HttpException(404, "No query results for model [{$type}] {$value}.");
            }

            return $model;
        });
    }

    // we need to inject the router and router manager, only DI here, no service locator

    // public function boot(Router $router, RouteLoader $routeLoader): void
    // {
    //     $routeLoader->load($router);
    // }

    public function boot(RouteLoader $routeLoader, Router $router, Kernel $kernel): void
    {
        $routeLoader->load($router);

        /*
         * Prepended, so a request to a site that is down is answered before any
         * other global middleware gets to touch it — and so it covers a 404 as
         * much as a hit, since a routing table being replaced mid-deploy is
         * exactly what maintenance mode is hiding.
         *
         * Registered here rather than declared on the Kernel because the guard
         * needs a MaintenanceMode, and the Http layer should not require a
         * Foundation service to exist before a Kernel can be built.
         */
        $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    }
}
