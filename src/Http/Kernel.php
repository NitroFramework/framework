<?php

namespace Nitro\Http;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Application;
use Nitro\Http\Contracts\Responsable;
use Nitro\Http\Exceptions\HttpResponseException;
use Nitro\Http\Middleware\AddQueuedCookiesToResponse;
use Nitro\Http\Middleware\EncryptCookies;
use Nitro\Http\Middleware\StartSession;
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
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            // Must precede VerifyCsrfToken, which reads the token off the
            // session this starts. Routes outside this group get no session
            // at all — no Store, no file read, no file write.
            StartSession::class,
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

    /**
     * Per-route gathered middleware-name lists, keyed by the route's own
     * middleware signature. The global stack + group expansion are deterministic
     * for a given route, so this is computed once and reused every request —
     * only the instances are resolved fresh (below), keeping request-scoped
     * middleware dependencies correct.
     *
     * @var array<string, array<int, string>>
     */
    private array $gatheredMiddlewareCache = [];

    /**
     * Resolved middleware name → class-string (or null when unresolvable). Pure
     * string lookup, safe to memoize for the kernel's lifetime; avoids repeating
     * the alias lookup + class_exists() on every request.
     *
     * @var array<string, string|null>
     */
    private array $middlewareClassCache = [];

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
        // Mark where this request's output buffering starts. If it fails, the
        // handler discards buffers opened above this line — a half-written
        // layout — without touching any the host (a Thrust worker) owns below it.
        ExceptionHandler::$requestObLevel = ob_get_level();

        try {
            $this->runHooks($this->requestReceivedHooks, $request);
            $response = $this->sendRequestThroughRouter($request);
            $this->runHooks($this->responseReadyHooks, $request, $response);
            return $response;
        } catch (HttpResponseException $exception) {
            // A helper (e.g. request()->validate()) short-circuited with a
            // ready response — send it as-is, then run response-ready hooks.
            $response = $exception->getResponse();
            $this->runHooks($this->responseReadyHooks, $request, $response);
            return $response;
        } catch (Throwable $exception) {
            return $this->handleException($request, $exception);
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
            // 'platform:admin' is the alias 'platform' with 'admin' as an
            // argument. Without the split the whole string resolves to nothing
            // and the middleware is skipped — which, on a guard, leaves a route
            // declared ->middleware('platform:admin') wide open while the route
            // list still shows it as protected.
            [$alias, $parameters] = $this->parseMiddlewareName($name);

            $middleware = $this->resolveRouteMiddleware($alias);
            if ($middleware === null) {
                continue;
            }
            $next = $finalNext;
            $finalNext = fn(Request $req) => $middleware->handle($req, $next, ...$parameters);
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
        $routeMiddleware = $resolvedRoute->getMiddleware();

        // The gathered list depends only on the route's own middleware (the
        // global stack + groups are fixed), so memoize by that signature and
        // skip the group expansion on every subsequent request for this shape.
        $key = $routeMiddleware === [] ? '' : implode("\0", $routeMiddleware);
        if (isset($this->gatheredMiddlewareCache[$key])) {
            return $this->gatheredMiddlewareCache[$key];
        }

        $gathered = $this->middleware;

        foreach ($routeMiddleware as $name) {
            if (isset($this->middlewareGroups[$name])) {
                foreach ($this->middlewareGroups[$name] as $groupMiddleware) {
                    $gathered[] = $groupMiddleware;
                }
                continue;
            }
            $gathered[] = $name;
        }

        return $this->gatheredMiddlewareCache[$key] = $gathered;
    }

    /**
     * Split 'alias:one,two' into its alias and its arguments.
     *
     * The colon only separates when the name is not a class: a fully-qualified
     * middleware class cannot contain one, but a Windows-ish path or an
     * unusual alias might, so the class check comes first.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function parseMiddlewareName(string $name): array
    {
        if (! str_contains($name, ':') || class_exists($name)) {
            return [$name, []];
        }

        [$alias, $arguments] = explode(':', $name, 2);

        return [$alias, $arguments === '' ? [] : explode(',', $arguments)];
    }

    /** Resolve a middleware alias (or a fully-qualified class name) to an instance. */
    protected function resolveRouteMiddleware(string $name): ?object
    {
        // Registered alias first (resolved from the Router); otherwise accept a
        // class-name middleware directly (->middleware(MyMiddleware::class)), so
        // app middleware works without registering an alias. The name→class step
        // is pure, so memoize it; the instance itself is still resolved per
        // request so request-scoped dependencies stay fresh.
        if (!array_key_exists($name, $this->middlewareClassCache)) {
            $this->middlewareClassCache[$name] =
                $this->router->getMiddlewareAlias($name)
                ?? ($name !== '' && class_exists($name) ? $name : null);
        }

        $class = $this->middlewareClassCache[$name];
        if ($class === null) {
            return null;
        }
        return $this->container->createOrResolve($class);
    }

    /** Dispatch the resolved route to its handler. */
    protected function dispatchToHandler(Route $resolvedRoute, Request $request): Response
    {
        // 1. Get the raw result from the Dispatcher
        $result = $this->dispatcher->dispatchToHandler($resolvedRoute, $request);

        // 2. Handle the "Decoupled View" (The DTO)
        if ($result instanceof ViewResponse) {
            $renderer = $this->container->createOrResolve(ViewEngine::class);
            return Response::html($renderer->render($result->template, $result->data));
        }

        // 3. Handle standard Responses
        if ($result instanceof Response) {
            return $result;
        }

        // 3b. An object that renders itself (API resources, self-serializing DTOs).
        if ($result instanceof Responsable) {
            return $result->toResponse($request);
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

    /**
     * The exception this kernel last turned into a response, if any.
     *
     * Kept so a test can ask to see the real failure rather than the rendered
     * error page: by the time a test has a response the exception has already
     * been converted, and the message, file and line are only recoverable from
     * several kilobytes of styled markup.
     */
    public function lastException(): ?Throwable
    {
        return $this->lastException;
    }

    protected ?Throwable $lastException = null;

    /** Handle an exception that occurred during the request. */
    protected function handleException(Request $request, Throwable $exception): Response
    {
        $this->lastException = $exception;

        $handler = $this->container->createOrResolve(ExceptionHandler::class);

        // Report ONCE, here, before deciding how to render. Doing it at the top
        // of the catch means an exception that converts to a redirect (a
        // validation failure) passes the same reporting rules as one that
        // renders a page — reporting inside render() silently skipped the first.
        $handler->report($exception);

        // Exceptions that convert to a full Response (e.g. a validation failure →
        // redirect-back / 422 JSON) are handled here, before the HTML renderer.
        // These fire responseReady hooks just like a normal response would.
        $converted = $handler->renderResponse($exception, $request);
        if ($converted instanceof Response) {
            $this->runHooks($this->responseReadyHooks, $request, $converted);
            return $converted;
        }

        $content = $handler->render($exception);
        $statusCode = $handler->getStatusCode($exception);

        if ($request->isHtmx()) {
            return new Response('', 200, [
                'HX-Redirect' => $request->path(),
            ]);
        }

        // Headers the exception itself asked for. A 429 without Retry-After
        // tells a client to back off for an unknown length of time, so it
        // retries immediately; a 401 without WWW-Authenticate does not say how
        // to authenticate. Losing these at the last step is how an
        // abort(429) ends up meaning less than the middleware that throws it.
        $headers = ['Content-Type' => 'text/html'];

        $prepared = $handler->prepareException($exception);

        if ($prepared instanceof HttpException) {
            $headers = array_merge($headers, $prepared->getHeaders());
        }

        $response = new Response($content, $statusCode, $headers);

        return $handler->finalize($response, $exception, $request) ?? $response;
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
    /**
     * The lifecycle hooks currently attached, keyed by seam name.
     *
     * Hooks are action-at-a-distance: reading handle() shows a runHooks() call
     * against an array whose contents were attached from some provider's boot().
     * Exposing them lets `nitro lifecycle` name what actually runs at each seam,
     * which is otherwise only discoverable by grepping the whole framework.
     *
     * @return array<string, array<int, callable>>
     */
    public function getLifecycleHooks(): array
    {
        return [
            'requestReceived' => $this->requestReceivedHooks,
            'responseReady'   => $this->responseReadyHooks,
            'terminating'     => $this->terminatingHooks,
        ];
    }

    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }
}
