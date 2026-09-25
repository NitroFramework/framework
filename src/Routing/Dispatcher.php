<?php

namespace Nitro\Routing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\Router as BaseRouter;
use Nitro\Foundation\Application;

/**
 * Match + run a route from the compiled table.
 *
 * Controller and closure arguments come from the compiled argument plan (the same algorithm as
 * ResolvesRouteDependencies, minus reflection). Anything the compiler could not plan, and any app
 * that rebinds the ControllerDispatcher contract, goes through Laravel's own Route::run().
 */
final class Dispatcher
{
    public ?Pipeline $lastPipeline = null;

    public function __construct(private Container $container, private readonly Router $router)
    {
    }

    public function dispatch(Request $request): mixed
    {
        /**
         * Always the router's current container: Octane hands the router a fresh sandbox
         * application per request, and controllers/middleware must resolve from it.
         */
        $this->container = $this->router->container();

        [$route, $entry] = $this->router->compiledRoutes()->find($request);

        $route->setContainer($this->container);
        $request->setRouteResolver(static fn () => $route);
        $this->router->setCurrentRoute($route, $request);

        $events = $this->container->make('events');

        if ($events->hasListeners(RouteMatched::class)) {
            $events->dispatch(new RouteMatched($route, $request));
        }

        $stack = $entry !== null
            ? $entry['middleware']
            : Pipeline::parse($this->router->gatherRouteMiddleware($route));

        if ($this->container->shouldSkipMiddleware()) {
            $stack = [];
        }

        $pipeline = $this->lastPipeline = new Pipeline($this->container);

        $response = $pipeline->run($stack, $request, function ($request) use ($route, $entry, $events) {
            return $this->prepare($request, $this->run($route, $entry), $events);
        });

        return $this->prepare($request, $response, $events);
    }

    private function prepare(Request $request, mixed $response, $events): mixed
    {
        if ($events->hasListeners(PreparingResponse::class)) {
            $events->dispatch(new PreparingResponse($request, $response));
        }

        $response = BaseRouter::toResponse($request, $response);

        if ($events->hasListeners(ResponsePrepared::class)) {
            $events->dispatch(new ResponsePrepared($request, $response));
        }

        return $response;
    }

    private function run(Route $route, ?array $entry): mixed
    {
        if ($entry === null || $entry['plan'] === null) {
            return $route->run();
        }

        try {
            if ($entry['controller'] !== null) {
                if ($this->customControllerDispatcher()) {
                    return $route->run();
                }

                [$class, $method] = $entry['controller'];

                $controller = $route->controller ??= $this->container->make($class);
                $parameters = $this->resolveArguments($entry['plan'], $route->parametersWithoutNulls());

                return method_exists($controller, 'callAction')
                    ? $controller->callAction($method, $parameters)
                    : $controller->{$method}(...array_values($parameters));
            }

            $callable = $route->getAction('uses');

            if (! $callable instanceof Closure) {
                if (! RouteAction::containsSerializedClosure($route->getAction())) {
                    return $route->run();
                }

                $callable = unserialize($callable)->getClosure();
            }

            return $callable(...array_values($this->resolveArguments($entry['plan'], $route->parametersWithoutNulls())));
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    private ?bool $customControllerDispatcher = null;

    private function customControllerDispatcher(): bool
    {
        /** An explicit binding (not Nitro's component default) means someone replaced the dispatcher. */
        return $this->customControllerDispatcher ??= $this->container instanceof Application
            && $this->container->hasExplicitBinding(ControllerDispatcherContract::class);
    }

    /**
     * ResolvesRouteDependencies::resolveMethodDependencies() over a compiled plan.
     */
    private function resolveArguments(array $plan, array $parameters): array
    {
        $instanceCount = 0;
        $values = array_values($parameters);

        foreach ($plan as $key => [$class, $hasDefault, $default, $isEnum]) {
            if ($class !== null && ! self::alreadyInParameters($class, $parameters)) {
                $instance = $hasDefault ? ($isEnum ? $default : null) : $this->container->make($class);
                $instanceCount++;
                array_splice($parameters, $key, 0, [$instance]);
            } elseif (! isset($values[$key - $instanceCount]) && $hasDefault) {
                array_splice($parameters, $key, 0, [$default]);
            }
        }

        return $parameters;
    }

    private static function alreadyInParameters(string $class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
