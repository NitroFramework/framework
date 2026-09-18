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
use Nitro\Session\Middleware\StartSession;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Http\ViewResponse;
use Nitro\Routing\RouteDispatcher;
use Nitro\Support\Pipeline;
use Nitro\Routing\Route;
use Nitro\Routing\Router;
use Nitro\View\Contracts\Engine;
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
     * Expanded middleware-name lists, keyed by a route's declared middleware.
     * Group expansion is deterministic for a given group map, so it is computed
     * once per route shape; instances are still resolved per request, keeping
     * request-scoped middleware dependencies correct.
     *
     * Invalidated only by {@see middlewareGroup()}.
     *
     * @var array<string, array<int, string>>
     */
    private array $gatheredMiddlewareCache = [];

    /**
     * Middleware name → class-string, for names that resolved. The lookup is
     * pure, so it is memoized for the kernel's lifetime rather than repeating
     * the alias check and class_exists() per request.
     *
     * Holds successful lookups only; see {@see resolveRouteMiddleware()}.
     *
     * @var array<string, class-string>
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

    /**
     * Handle an incoming HTTP request.
     *
     * Has exactly one return, through {@see finish()}, so that every way a
     * request can end — matched route, 404, validation short-circuit,
     * unhandled throwable — passes the responseReady seam. Hooks registered
     * there emit the session cookie and rewrite HTMX responses; a path that
     * skips them produces a response that looks correct and is missing a
     * header.
     *
     * Enforced by ResponseSeamGuardTest.
     */
    public function handle(Request $request): Response
    {
        // Mark where this request's output buffering starts. If it fails, the
        // handler discards buffers opened above this line — a half-written
        // layout — without touching any the host (a Thrust worker) owns below it.
        ExceptionHandler::$requestObLevel = ob_get_level();

        return $this->finish($request, $this->buildResponse($request));
    }

    /**
     * Produce the response for a request, by whichever path it takes.
     *
     * Deliberately runs no responseReady hooks: {@see handle()} is its only
     * caller and funnels every result through {@see finish()}, so the seam is
     * applied once regardless of which branch returned.
     */
    private function buildResponse(Request $request): Response
    {
        try {
            $this->runHooks($this->requestReceivedHooks, $request);

            return $this->sendRequestThroughRouter($request);
        } catch (HttpResponseException $exception) {
            // A helper (e.g. request()->validate()) short-circuited with a
            // ready response — send it as-is.
            return $exception->getResponse();
        } catch (Throwable $exception) {
            return $this->renderException($request, $exception);
        }
    }

    /**
     * Run the responseReady seam over a finished response and return it.
     *
     * A failing hook is reported rather than swallowed, and rethrown in debug.
     * Reporting it keeps a missing session cookie traceable; returning the
     * response anyway avoids replacing a successful result with an error page
     * because a post-processing step failed.
     */
    private function finish(Request $request, Response $response): Response
    {
        try {
            $this->runHooks($this->responseReadyHooks, $request, $response);
        } catch (Throwable $exception) {
            $this->container->createOrResolve(ExceptionHandler::class)->report($exception);

            if ($this->app->isDebug()) {
                throw $exception;
            }
        }

        return $response;
    }

    /**
     * Route the request through matching, middleware, and dispatch:
     *
     *   global stack → route matching → route stack → handler
     *
     * The global stack wraps matching rather than sitting inside it, so it also
     * covers requests that match nothing. Cross-cutting middleware — CORS,
     * trusted proxies, maintenance mode — has to apply to a 404 as much as to a
     * hit, or a mistyped URL fails in the client with a CORS error instead of
     * returning a readable 404.
     *
     * Route middleware runs inside matching because until a route is resolved
     * there is no list to read.
     *
     * The match is attached to the request here, once resolved, so that
     * {@see Request::route()} and routeIs() answer for route middleware and the
     * handler while global middleware — which runs before a route is known —
     * correctly sees none.
     *
     * Explicitly bound parameters are resolved at the same point, before any
     * route middleware runs, so a guard reading $request->route('user') sees
     * the model rather than the raw segment.
     */
    protected function sendRequestThroughRouter(Request $request): Response
    {
        return $this->pipeline(
            $this->middleware,
            $request,
            function (Request $request): Response {
                $resolvedRoute = $this->router->findMatchingRoute($request);

                if (! $resolvedRoute) {
                    return $this->createNotFoundResponse($request);
                }

                $this->router->substituteBindings($resolvedRoute);

                $request->setRouteResolver(static fn () => $resolvedRoute);

                return $this->pipeline(
                    $this->gatherMiddleware($resolvedRoute),
                    $request,
                    fn (Request $request): Response => $this->dispatchToHandler($resolvedRoute, $request),
                );
            },
        );
    }

    /**
     * Compose a middleware list around a destination and run it.
     *
     * Names are resolved before the pipeline runs, so 'alias:args' is split
     * into the alias and its arguments here rather than in {@see Pipeline}.
     *
     * @param  array<int, string>          $middlewareNames Aliases, 'alias:args', or class names.
     * @param  callable(Request): Response $destination     Invoked once all middleware have called $next.
     * @return Response
     *
     * @throws RuntimeException When a name resolves to no middleware.
     */
    protected function pipeline(array $middlewareNames, Request $request, callable $destination): Response
    {
        if ($middlewareNames === []) {
            return $destination($request);
        }

        $stages = [];

        foreach ($middlewareNames as $name) {
            [$alias, $parameters] = $this->parseMiddlewareName($name);

            $middleware = $this->resolveRouteMiddleware($alias);

            $stages[] = static fn (Request $request, callable $next): Response
                => $middleware->handle($request, $next, ...$parameters);
        }

        return Pipeline::make($this->container)
            ->send($request)
            ->through($stages)
            ->then(static fn (Request $request): Response => $destination($request));
    }

    /**
     * Expand a route's declared middleware, replacing any group name (e.g.
     * 'web') with that group's members and leaving other names untouched.
     *
     * The global stack is not included: it wraps routing one level out, in
     * {@see sendRequestThroughRouter()}.
     *
     * @return array<int, string>
     */
    protected function gatherMiddleware(Route $resolvedRoute): array
    {
        $routeMiddleware = $resolvedRoute->getMiddleware();

        // Expansion depends only on the declared list and the group map, so it
        // is memoized by that list rather than repeated per request.
        // middlewareGroup() clears the cache when the map changes.
        $key = $routeMiddleware === [] ? '' : implode("\0", $routeMiddleware);
        if (isset($this->gatheredMiddlewareCache[$key])) {
            return $this->gatheredMiddlewareCache[$key];
        }

        $gathered = [];

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
     * Append middleware to the global stack, which runs on every request
     * whether or not it matches a route. Already-registered names are ignored,
     * so registration is idempotent across repeated provider boots.
     *
     * @param string|array<int, string> $middleware Class names or registered aliases.
     */
    public function pushMiddleware(string|array $middleware): static
    {
        foreach ((array) $middleware as $name) {
            if (! in_array($name, $this->middleware, true)) {
                $this->middleware[] = $name;
            }
        }

        return $this;
    }

    /**
     * Prepend middleware to the global stack, ahead of anything already
     * registered — for middleware that must observe the request before other
     * middleware can alter it, such as trusted-proxy handling.
     *
     * @param string|array<int, string> $middleware Class names or registered aliases, in final order.
     */
    public function prependMiddleware(string|array $middleware): static
    {
        foreach (array_reverse((array) $middleware) as $name) {
            if (! in_array($name, $this->middleware, true)) {
                array_unshift($this->middleware, $name);
            }
        }

        return $this;
    }

    /**
     * Define or replace a middleware group.
     *
     * Clears the gathered-list cache, whose entries hold the old expansion of
     * this name. The kernel is a singleton, so under Thrust a stale entry would
     * survive until the worker restarted rather than correcting itself on the
     * next request.
     *
     * @param array<int, string> $middleware Members of the group, in run order.
     */
    public function middlewareGroup(string $name, array $middleware): static
    {
        $this->middlewareGroups[$name] = $middleware;
        $this->gatheredMiddlewareCache = [];

        return $this;
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

    /**
     * Resolve a middleware alias, or a fully-qualified class name, to an
     * instance.
     *
     * An unresolvable name throws, including in production. Skipping it instead
     * would make a misspelled guard indistinguishable from a guard that passed:
     * the route would run no check while route:list still reported it as
     * protected. A 500 is the safer failure.
     *
     * Only successful lookups are cached; a negative result must stay
     * uncached so that an alias registered later is still found, which matters
     * in a Thrust worker where the kernel is never rebuilt.
     *
     * @throws RuntimeException When the name is neither a registered alias nor an existing class.
     */
    protected function resolveRouteMiddleware(string $name): object
    {
        // Registered alias first (resolved from the Router); otherwise accept a
        // class-name middleware directly (->middleware(MyMiddleware::class)), so
        // app middleware works without registering an alias. The name→class step
        // is pure, so memoize it; the instance itself is still resolved per
        // request so request-scoped dependencies stay fresh.
        if (! isset($this->middlewareClassCache[$name])) {
            $class = $this->router->getMiddlewareAlias($name)
                ?? ($name !== '' && class_exists($name) ? $name : null);

            if ($class === null) {
                $aliases = array_keys($this->router->getMiddlewareAliases());
                sort($aliases);

                throw new RuntimeException(
                    "Middleware [{$name}] is not a registered alias and is not an existing class. "
                    . 'A route declaring it would otherwise run no check at all while still '
                    . "appearing protected in route:list. Registered aliases: "
                    . ($aliases === [] ? '(none)' : implode(', ', $aliases)) . '.'
                );
            }

            $this->middlewareClassCache[$name] = $class;
        }

        return $this->container->createOrResolve($this->middlewareClassCache[$name]);
    }

    /** Dispatch the resolved route to its handler. */
    protected function dispatchToHandler(Route $resolvedRoute, Request $request): Response
    {
        // 1. Get the raw result from the Dispatcher
        $result = $this->dispatcher->dispatchToHandler($resolvedRoute, $request);

        // 2. Handle the "Decoupled View" (The DTO)
        if ($result instanceof ViewResponse) {
            $renderer = $this->container->createOrResolve(Engine::class);
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

    /**
     * Turn an exception into a Response.
     *
     * Reports once, then renders — either through a registered response handler
     * (a validation failure becoming a redirect or a 422) or as an error page.
     *
     * Runs no responseReady hooks. Both of its callers return through
     * {@see handle()}, which applies the seam to whatever comes back.
     */
    protected function renderException(Request $request, Throwable $exception): Response
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
        $converted = $handler->renderResponse($exception, $request);
        if ($converted instanceof Response) {
            return $converted;
        }

        $statusCode = $handler->getStatusCode($exception);

        $content = $handler->render($exception);

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

        return $this->renderException($request, new HttpException(404, $message));
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

    /**
     * Middleware aliases a route may name, as [alias => class].
     *
     * Registered on the Router by feature providers; exposed here so that
     * `nitro lifecycle` can report the whole middleware picture from one place.
     *
     * @return array<string, class-string>
     */
    public function getMiddlewareAliases(): array
    {
        return $this->router->getMiddlewareAliases();
    }
}
