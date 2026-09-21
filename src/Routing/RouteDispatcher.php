<?php

namespace Nitro\Routing;

use Closure;
use Nitro\Actions\Action;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Http\ViewResponse;
use Nitro\Http\Request;
use RuntimeException;

/**
 * Executes a matched route's handler and returns its raw result.
 *
 * Resolves and invokes controllers, closures and callables through the
 * container (so dependencies and route parameters auto-wire), and turns view
 * routes into a {@see ViewResponse} DTO for the HTTP kernel to render. Any
 * other kind of route belongs to the layer that defined it, and is handed
 * back to it through {@see RouteTypes}. This is framework infrastructure,
 * not a developer-facing API.
 */
class RouteDispatcher
{
    /**
     * @param ClassResolver   $resolver   Builds the controller a route names.
     * @param CallableInvoker $invoker    Calls the handler with its dependencies
     *        and route parameters bound.
     * @param RouteTypes      $routeTypes The kinds of route feature layers added;
     *        consulted only for a type this class has no arm for.
     */
    public function __construct(
        protected ClassResolver $resolver,
        protected CallableInvoker $invoker,
        protected RouteTypes $routeTypes = new RouteTypes(),
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
            default => $this->dispatchToContributedType($route, $request),
        };
    }

    /**
     * Hand a route of a contributed kind back to the layer that defined it.
     *
     * @throws RuntimeException When no layer claims the type.
     */
    protected function dispatchToContributedType(Route $route, Request $request): mixed
    {
        $type = $this->routeTypes->get($route->getType());

        if ($type === null) {
            throw new RuntimeException("Unknown route type: {$route->getType()}");
        }

        return $type->dispatch($route, $request);
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

        // The resolver throws a descriptive RuntimeException if the class
        // doesn't exist; the invoker throws if the method doesn't exist.
        // Pre-validating with class_exists / method_exists duplicates that work
        // on the hot path for every dispatch.
        $controller = $this->resolver->resolve($controllerClass);

        // Single-action classes run through their own pipeline (authorize →
        // validate → body → response negotiation) instead of a plain call.
        if ($controller instanceof Action) {
            return $controller->runAsController($request, $parameters, $this->invoker);
        }

        /*
         * A controller may wrap its own actions. Arguments are resolved first
         * and handed over, so callAction() sees what the action will actually
         * be passed rather than having to bind anything itself.
         */
        if (method_exists($controller, 'callAction')) {
            return $controller->callAction(
                $method,
                $this->invoker->arguments($controller, $method, $parameters)
            );
        }

        return $this->invoker->call([$controller, $method], $parameters);
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

        return $this->invoker->call($closure, $parameters);
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

        // Route through the invoker so named route params bind correctly and
        // typed dependencies auto-wire (same behavior as closure handlers).
        return $this->invoker->call($callable, $parameters);
    }
}
