<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Container;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Database\Model\Model;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Routing\Route;
use Nitro\Http\Middleware\EncryptCookies;
use Nitro\Http\Middleware\PreventRequestsDuringMaintenance;
use Nitro\Http\Middleware\ValidateSignature;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Session\Middleware\StartSession;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;

/**
 * Registers the router, route loader, dispatcher and route-model binding.
 */
class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
       /*
        * Before the router, which is handed it: a layer registering a route
        * type and the router reading one must be looking at the same registry.
        */
       $this->container->singleton(RouteTypes::class, RouteTypes::class);

       $this->container->singleton(Router::class, Router::class);

        $this->container->singleton(RouteLoader::class, RouteLoader::class);

       $this->container->singleton(RouteDispatcher::class, RouteDispatcher::class);

        $this->container->alias(RouterInterface::class, Router::class);
        $this->container->alias('router', Router::class);
        $this->container->alias('routeLoader', RouteLoader::class);

        $this->registerRouteModelBinding();
    }

    /**
     * Hand the routing layer and the kernel the bus they raise events on.
     *
     * Both emitted lifecycle events from the day they were written and neither
     * had ever fired, because an emitter with no dispatcher does not fail — it
     * goes quiet. Asked for rather than assumed, so anything that does not emit
     * events is simply not offered one.
     */
    protected function wireEventDispatcher(Router $router, Kernel $kernel): void
    {
        $events = $this->container->resolve(EventDispatcher::class);

        foreach ([$router, $kernel] as $emitter) {
            if ($emitter instanceof ReceivesDispatcher) {
                $emitter->setDispatcher($events);
            }
        }
    }

    /**
     * Implicit route-model binding: a controller/closure parameter type-hinted
     * as a model whose name matches a route segment (e.g. /users/{user} →
     * show(User $user)) is resolved through the model, 404-ing when missing.
     *
     * The lookup goes through {@see Model::resolveRouteBinding()} rather than
     * find(), so a model can override how it is found and a route can name the
     * column itself with "{post:slug}". Calling find() here meant neither ever
     * ran: getRouteKeyName() and resolveRouteBinding() existed on the model and
     * nothing in the framework reached them.
     *
     * Registered as a container parameter binder so the core stays unaware of
     * the Database/HTTP layers — the policy lives here, in the composition root.
     */
    protected function registerRouteModelBinding(): void
    {
        $container = $this->container;

        $container->bindParametersUsing(function (string $type, mixed $value, string $name = '') use ($container) {
            if (!is_subclass_of($type, Model::class)) {
                return Container::PARAM_UNRESOLVED;
            }

            $route = $this->currentRoute($container);
            $model = $this->bindModel($type, $value, $name, $route);

            if ($model !== null) {
                /*
                 * Written back so a scoped child can resolve through it: the
                 * container binds arguments in signature order, which for a
                 * scoped route is the order the path declares them.
                 */
                $route?->setParameter($name, $model);

                return $model;
            }

            if ($route !== null && ($handler = $route->missingHandler()) !== null) {
                return $handler($value, $name);
            }

            throw new HttpException(404, "No query results for model [{$type}] {$value}.");
        });
    }

    /**
     * Find the model a route parameter refers to.
     *
     * A scoped route resolves through the parameter declared before this one,
     * so a comment is looked up on its post rather than globally.
     */
    protected function bindModel(string $type, mixed $value, string $name, ?Route $route): ?Model
    {
        $field = $route?->getBindingField($name);

        if ($route !== null && $route->isScoped()) {
            $parent = $route->parameter((string) $route->parentParameter($name));

            if ($parent instanceof Model) {
                return $parent->resolveChildRouteBinding($name, $value, $field);
            }
        }

        if ($route !== null && $route->includesTrashed() && method_exists($type, 'withTrashed')) {
            $instance = new $type();

            return $type::withTrashed()
                ->where($field ?? $instance->getRouteKeyName(), $value)
                ->first();
        }

        return (new $type())->resolveRouteBinding($value, $field);
    }

    /**
     * The route being served, or null outside a request.
     *
     * Read off the request rather than the router, so a swapped-in router is
     * not required to expose a current-route accessor.
     */
    protected function currentRoute(ContainerInterface $container): ?Route
    {
        if (! $container->has(Request::class)) {
            return null;
        }

        $route = $container->resolve(Request::class)->route();

        return $route instanceof Route ? $route : null;
    }

    // we need to inject the router and router manager, only DI here, no service locator

    // public function boot(Router $router, RouteLoader $routeLoader): void
    // {
    //     $routeLoader->load($router);
    // }

    public function boot(RouteLoader $routeLoader, Router $router, Kernel $kernel): void
    {
        /* Registered before routes load: a route may declare ->middleware('signed'). */
        $router->aliasMiddleware('signed', ValidateSignature::class);

        /*
         * The web group names these by class, so withoutMiddleware('csrf')
         * had nothing to match and silently excluded nothing.
         */
        $router->aliasMiddleware('csrf', VerifyCsrfToken::class);
        $router->aliasMiddleware('cookies', EncryptCookies::class);
        $router->aliasMiddleware('session', StartSession::class);

        $this->wireEventDispatcher($router, $kernel);

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
