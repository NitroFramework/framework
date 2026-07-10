<?php

namespace Nitro\Routing;

use Closure;
use Nitro\Actions\Action;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Http\ViewResponse;
use Nitro\Http\Request;
use RuntimeException;

/**
 * Executes a matched route's handler and returns its raw result.
 *
 * Resolves and invokes controllers, closures and callables through the
 * container (so dependencies and route parameters auto-wire), and turns view
 * routes into a {@see ViewResponse} DTO for the HTTP kernel to render. This is
 * framework infrastructure, not a developer-facing API.
 */
class RouteDispatcher
{
    /**
     * @param ContainerInterface $container Used to resolve controllers and to
     *        invoke handlers with dependency/parameter injection.
     */
    public function __construct(
        protected ContainerInterface $container,
    ) {}

    /**
     * Dispatch a matched route to the handler implied by its type and return
     * the raw result (Response, string, array, or a ViewResponse DTO).
     *
     * @throws RuntimeException When the route type is unrecognized.
     */
    public function dispatchToHandler(Route $route, Request $request): mixed
    {
        return match ($route->getType()) {
            Route::TYPE_CONTROLLER => $this->executeController($route, $request),
            Route::TYPE_CLOSURE    => $this->executeClosure($route),
            Route::TYPE_CALLABLE   => $this->executeCallable($route),
            Route::TYPE_VIEW       => $this->renderView($route),
            default => throw new RuntimeException("Unknown route type: {$route->getType()}")
        };
    }

    /**
     * Turn a view route into a {@see ViewResponse} DTO rather than rendering
     * it here.
     *
     * Rendering is deferred to the kernel so the dispatcher stays focused on
     * dispatching: it produces a result, the kernel decides how to render it.
     */
    protected function renderView(Route $route): ViewResponse
    {
        return new ViewResponse(
            $route->getViewName(),
            $route->getData()
        );
    }

    /**
     * Resolve the controller from the container and invoke the target method
     * with the route's bound parameters.
     *
     * @throws RuntimeException When the route lacks a controller class/method.
     */
    protected function executeController(Route $route, Request $request): mixed
    {
        $controllerClass = $route->getControllerClass();
        $method          = $route->getControllerMethod();
        $parameters      = $route->getParameters();

        if (!$controllerClass || !$method) {
            throw new RuntimeException("Invalid controller route configuration");
        }

        // The container throws a descriptive RuntimeException if the class
        // doesn't exist; Container::call throws if the method doesn't exist.
        // Pre-validating with class_exists / method_exists duplicates that work
        // on the hot path for every dispatch.
        $controller = $this->container->make($controllerClass);

        // Single-action classes run through their own pipeline (authorize →
        // validate → body → response negotiation) instead of a plain call.
        if ($controller instanceof Action) {
            return $controller->runAsController($request, $parameters, $this->container);
        }

        return $this->container->call([$controller, $method], $parameters);
    }

    /**
     * Invoke a closure handler through the container so its typed
     * dependencies and route parameters bind automatically.
     *
     * @throws RuntimeException When the handler is not a Closure.
     */
    protected function executeClosure(Route $route): mixed
    {
        $closure = $route->getHandler();
        $parameters = $route->getParameters();

        if (!$closure instanceof Closure) {
            throw new RuntimeException("Invalid closure handler");
        }

        return $this->container->call($closure, $parameters);
    }

    /**
     * Invoke a non-closure callable handler through the container, matching
     * the binding behaviour of closure handlers.
     *
     * @throws RuntimeException When the handler is not callable.
     */
    protected function executeCallable(Route $route): mixed
    {
        $callable = $route->getHandler();
        $parameters = $route->getParameters();

        if (!is_callable($callable)) {
            throw new RuntimeException("Handler is not callable");
        }

        // Route through the container so named route params bind correctly and
        // typed dependencies auto-wire (same behavior as closure handlers).
        return $this->container->call($callable, $parameters);
    }
}
