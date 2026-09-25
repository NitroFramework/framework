<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Database\Model\Model;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\EncryptCookies;
use Nitro\Http\Middleware\PreventRequestsDuringMaintenance;
use Nitro\Http\Middleware\ValidateSignature;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use Nitro\Session\Middleware\StartSession;

/**
 * Register the router, route loader, dispatcher and route-model binding.
 */
class RoutingServiceProvider extends ServiceProvider
{
    /** Register the routing services, the route-type registry first since the router reads it. */
    public function register(): void
    {
        $this->container->singleton(RouteTypes::class);
        $this->container->singleton(Router::class);
        $this->container->singleton(RouteLoader::class);
        $this->container->singleton(RouteDispatcher::class);

        $this->container->alias(Router::class, RouterInterface::class);
        $this->container->alias(Router::class, 'router');
        $this->container->alias(RouteLoader::class, 'routeLoader');

        $this->registerRouteModelBinding();
    }

    /** Give the router and the kernel the event bus, when they emit events. */
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
     * Resolve a handler parameter type-hinted as a model from the route segment of the same name.
     *
     * The model's resolveRouteBinding() finds the record, so a route can name the
     * column with {post:slug}. A missing record calls the route's missing handler,
     * or answers 404.
     */
    protected function registerRouteModelBinding(): void
    {
        $container = $this->container;

        $invoker = $container->get(CallableInvoker::class);

        $invoker->bindParametersUsing(function (string $type, mixed $value, string $name = '') use ($container) {
            if (!is_subclass_of($type, Model::class)) {
                return CallableInvoker::PARAM_UNRESOLVED;
            }

            $route = $this->currentRoute($container);
            $model = $this->bindModel($type, $value, $name, $route);

            if ($model !== null) {
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
     * A scoped route finds it through the parameter declared before this one.
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

    /** Get the route being served, or null outside a request. */
    protected function currentRoute(ContainerInterface $container): ?Route
    {
        if (! $container->has(Request::class)) {
            return null;
        }

        $route = $container->resolve(Request::class)->route();

        return $route instanceof Route ? $route : null;
    }

    /**
     * Register the framework's middleware aliases, load the routes and put the maintenance guard first.
     */
    public function boot(RouteLoader $routeLoader, Router $router, Kernel $kernel): void
    {
        $router->aliasMiddleware('signed', ValidateSignature::class);
        $router->aliasMiddleware('csrf', VerifyCsrfToken::class);
        $router->aliasMiddleware('cookies', EncryptCookies::class);
        $router->aliasMiddleware('session', StartSession::class);

        $this->wireEventDispatcher($router, $kernel);

        $routeLoader->load($router);

        $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    }
}
