<?php

namespace Nitro\Foundation\Http;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Application;
use Nitro\Http\Exceptions\HttpResponseException;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Http\ViewResponse;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Route;
use Nitro\Routing\Router;
use Nitro\View\Contracts\ViewEngine;
use RuntimeException;
use Throwable;


/**
 * The HTTP kernel — orchestrates the request lifecycle: capture, route, middleware, dispatch, respond, terminate.
 */
class Kernel
{
    protected Application $app;

    protected Router $router;
    protected ContainerInterface $container;

    protected array $middleware = [];

    protected array $middlewareGroups = [
        'web' => [
            VerifyCsrfToken::class,
        ],
        'api' => [],
    ];

    // Route-middleware aliases live on the Router, not here — feature providers
    // register them via Router::aliasMiddleware() in boot(), so the core kernel
    // never names a feature layer's middleware.

    private array $requestReceivedHooks = [];
    private array $responseReadyHooks = [];
    private array $terminatingHooks = [];

    protected RouteDispatcher $dispatcher;

    public function __construct(
        Application $app,
        Router $router,
        RouteDispatcher $dispatcher
    ) {
        $this->app = $app;
        $this->router = $router;
        $this->dispatcher = $dispatcher;
        $this->container = $app->getContainer();
    }

    public function run(): void
    {
        $request = Request::capture();
        $this->container->instance('request', $request);
        $this->container->instance(Request::class, $request);

        $response = $this->handle($request);

        // Response mutation (perf-bar injection, HTMX nav trimming, …) happens in
        // responseReady hooks registered by feature providers — the core kernel
        // just sends what handle() produced.
        $response->send();
        $this->terminate($request, $response);
    }

    

    // --- Request Handling ---

    /** Handle an incoming HTTP request. */
    public function handle(Request $request): Response
    {
        try {
            $this->runHooks($this->requestReceivedHooks, $request);
            $response = $this->sendRequestThroughRouter($request);
            $this->runHooks($this->responseReadyHooks, $request, $response);
            return $response;
        } catch (HttpResponseException $e) {
            // A helper (e.g. request()->validate()) short-circuited with a
            // ready response — send it as-is, then run response-ready hooks.
            $response = $e->getResponse();
            $this->runHooks($this->responseReadyHooks, $request, $response);
            return $response;
        } catch (Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    /** Route the request through matching, middleware, and dispatch. */
    protected function sendRequestThroughRouter(Request $request): Response
    {
        $resolvedRoute = $this->router->findMatchingRoute($request);

        if (!$resolvedRoute) {
            return $this->createNotFoundResponse($request);
        }

        $middlewareNames = $this->gatherMiddleware($resolvedRoute);
        if (empty($middlewareNames)) {
            return $this->dispatchToHandler($resolvedRoute, $request);
        }

        $finalNext = fn(Request $req) => $this->dispatchToHandler($resolvedRoute, $req);

        // Compose inside-out: reverse so the first-listed middleware is the
        // outermost wrapper and therefore runs first.
        foreach (array_reverse($middlewareNames) as $name) {
            $middleware = $this->resolveRouteMiddleware($name);
            if ($middleware === null) {
                continue;
            }
            $next = $finalNext;
            $finalNext = fn(Request $req) => $middleware->handle($req, $next);
        }

        return $finalNext($request);
    }

    /**
     * Build the ordered middleware list for a route: the global stack first
     * (runs on every request), then the route's own middleware — expanding any
     * name that refers to a middleware group (e.g. 'web') into that group's
     * members. Mirrors Laravel's gatherRouteMiddleware + name resolution.
     */
    protected function gatherMiddleware(Route $resolvedRoute): array
    {
        $gathered = $this->middleware;

        foreach ($resolvedRoute->getMiddleware() as $name) {
            if (isset($this->middlewareGroups[$name])) {
                foreach ($this->middlewareGroups[$name] as $groupMiddleware) {
                    $gathered[] = $groupMiddleware;
                }
                continue;
            }
            $gathered[] = $name;
        }

        return $gathered;
    }

    /** Resolve a middleware alias (or a fully-qualified class name) to an instance. */
    protected function resolveRouteMiddleware(string $name): ?object
    {
        // Registered alias first (resolved from the Router); otherwise accept a
        // class-name middleware directly (Laravel allows ->middleware(MyMiddleware::class)),
        // so app middleware works without registering an alias.
        $class = $this->router->getMiddlewareAlias($name) ?? ($name !== '' && class_exists($name) ? $name : null);
        if ($class === null) {
            return null;
        }
        return $this->container->make($class);
    }

    /** Dispatch the resolved route to its handler. */
    protected function dispatchToHandler(Route $resolvedRoute, Request $request): Response
    {
        // 1. Get the raw result from the Dispatcher
        $result = $this->dispatcher->dispatchToHandler($resolvedRoute, $request);

        // 2. Handle the "Decoupled View" (The DTO)
        if ($result instanceof ViewResponse) {
            $renderer = $this->container->make(ViewEngine::class);
            return Response::html($renderer->render($result->template, $result->data));
        }

        // 3. Handle standard Responses
        if ($result instanceof Response) {
            return $result;
        }

        // 4. Handle Strings (HTML)
        if (is_string($result)) {
            return Response::html($result);
        }

        // 5. Handle Arrays/Objects (JSON)
        if (is_array($result) || is_object($result)) {
            return Response::json((array) $result);
        }

        return Response::html((string) $result);
    }

    /** Handle an exception that occurred during the request. */
    protected function handleException(Request $request, Throwable $e): Response
    {
        $handler = $this->container->make(ExceptionHandler::class);

        // Exceptions that convert to a full Response (e.g. a validation failure →
        // redirect-back / 422 JSON) are handled here, before the HTML renderer.
        // These fire responseReady hooks just like a normal response would.
        $converted = $handler->renderResponse($e, $request);
        if ($converted instanceof Response) {
            $this->runHooks($this->responseReadyHooks, $request, $converted);
            return $converted;
        }

        $content = $handler->render($e);
        $statusCode = $handler->getStatusCode($e);

        if ($request->isHtmx()) {
            return new Response('', 200, [
                'HX-Redirect' => $request->path(),
            ]);
        }

        return new Response($content, $statusCode, ['Content-Type' => 'text/html']);
    }

    /** Create a 404 Not Found response. */
    protected function createNotFoundResponse(Request $request): Response
    {
        $message = "Route not found: {$request->method()} {$request->path()}";
        return $this->handleException($request, new HttpException(404, $message));
    }

    /** Run cleanup tasks after the response has been sent. */
    public function terminate(Request $request, Response $response): void
    {
        $this->runHooks($this->terminatingHooks, $request, $response);
    }

    // --- Hook Points ---

    /** Register a hook to run when a request is received. */
    public function requestReceived(callable $hook): void
    {
        $this->requestReceivedHooks[] = $hook;
    }

    /** Register a hook to run when a response is ready. */
    public function responseReady(callable $hook): void
    {
        $this->responseReadyHooks[] = $hook;
    }

    /** Register a hook to run during termination. */
    public function terminating(callable $hook): void
    {
        $this->terminatingHooks[] = $hook;
    }

    /** Run a list of lifecycle hooks, forwarding the given arguments to each. */
    protected function runHooks(array $hooks, mixed ...$args): void
    {
        foreach ($hooks as $hook) {
            $hook(...$args);
        }
    }

    // --- Middleware Accessors ---

    /** Get the global middleware stack. */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /** Get middleware groups. */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }
}
