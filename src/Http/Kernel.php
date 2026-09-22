<?php

namespace Nitro\Http;

use Nitro\Container\Contracts\ClassResolver;
use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Events\CoreEvents;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Application;
use Nitro\Foundation\BootProfile;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Http\Contracts\Responsable;
use Nitro\Http\Controller\HasMiddleware;
use Nitro\Http\Controller\Middleware as ControllerMiddleware;
use Nitro\Http\Events\RequestEvent;
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
use Nitro\Routing\Contracts\ReportsAllowedMethods;
use Nitro\Routing\Contracts\RouterInterface as Router;
use Nitro\Routing\Events\RouteEvent;
use Nitro\Routing\Events\RoutingEvents;
use Nitro\View\Contracts\Engine;
use RuntimeException;
use Throwable;


/**
 * The HTTP kernel — orchestrates the request lifecycle: capture, route, middleware, dispatch, respond, terminate.
 */
class Kernel implements ReceivesDispatcher
{
    use DispatchesEvents;

    protected Application $app;

    protected Router $router;
    protected Container $container;

    /**
     * The global stack, run on every request whether or not it matches a route.
     *
     * Empty by default, and filled by providers through {@see pushMiddleware()}.
     * Naming a class here instead would make constructing a Kernel require
     * whatever that class needs — the maintenance guard wants a MaintenanceMode,
     * which is a Foundation service the Http layer should not have to assume
     * exists.
     *
     * @var array<int, string>
     */
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

    /**
     * Middleware that must run in this order relative to one another, whatever
     * order a route or a group happened to list them in.
     *
     * Some middleware depend on what an earlier one did: VerifyCsrfToken reads
     * the token off the session StartSession opened, and Authenticate needs
     * that session to know who is logged in. A route written as
     * ->middleware(['auth', 'web']) reads perfectly sensibly and used to run
     * the auth check against a session that did not exist yet.
     *
     * Only the names listed here are reordered, and only relative to each
     * other; anything unlisted keeps the position it was given.
     *
     * @var array<int, class-string>
     */
    protected array $middlewarePriority = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        VerifyCsrfToken::class,
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

    /**
     * Named for what it dispatches. It was $dispatcher, which reads as the
     * event bus everywhere else in the framework — and the kernel raises
     * events too, so the two would be indistinguishable here of all places.
     */
    protected RouteDispatcher $routeDispatcher;

    public function __construct(
        Application $app,
        Router $router,
        RouteDispatcher $routeDispatcher
    ) {
        $this->app = $app;
        $this->router = $router;
        $this->routeDispatcher = $routeDispatcher;
        $this->container = $app->getContainer();
    }

    public function run(): void
    {
        $request = Request::capture();
        $this->container->instance('request', $request);
        $this->container->instance(Request::class, $request);

        BootProfile::mark('capture');

        $response = $this->handle($request);

        // Response mutation (perf-bar injection, HTMX nav trimming, …) happens in
        // responseReady hooks registered by feature providers — the core kernel
        // just sends what handle() produced.
        /**
         * Emit point — response.sending
         *
         * The last moment before headers and body go out. A listener runs
         * inside the client's wait, so anything slow here is felt directly —
         * and it is already too late to change the response, which is what
         * the responseReady hooks are for.
         * Payload: {@see RequestEvent}.
         */
        $this->eventLazy(
            CoreEvents::RESPONSE_SENDING,
            fn (): RequestEvent => new RequestEvent(
                $request->method(),
                $request->path(),
                $response->getStatusCode(),
            ),
        );

        BootProfile::mark('responseReady');

        $response->send();

        /**
         * Emit point — response.sent
         *
         * The bytes have left. Nothing a listener does can affect what the
         * client received, so this and app.terminating are where after-the-
         * fact work belongs.
         * Payload: {@see RequestEvent}.
         */
        $this->eventLazy(
            CoreEvents::RESPONSE_SENT,
            fn (): RequestEvent => new RequestEvent(
                $request->method(),
                $request->path(),
                $response->getStatusCode(),
            ),
        );

        /*
         * Taken after send() rather than after terminate(), so the figure is
         * what the client waited for. Written here because php-fpm tears the
         * process down next, and a profile that is never flushed measures
         * nothing.
         */
        if (BootProfile::enabled()) {
            BootProfile::mark('send');
            BootProfile::write(
                $this->container->resolve(PathRegistry::class)->storage('logs/profile.log'),
                $request->method(),
                $request->path(),
            );
        }

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

            /**
             * Emit point — request.received
             *
             * Once per request, after the requestReceived hooks and before any
             * middleware or routing. Nothing has been matched yet, so a
             * listener sees the request as it arrived and cannot know which
             * route will serve it. To change or reject a request, use
             * middleware — an event cannot stop anything.
             * Payload: {@see RequestEvent}.
             */
            $this->eventLazy(
                CoreEvents::REQUEST_RECEIVED,
                fn (): RequestEvent => new RequestEvent($request->method(), $request->path()),
            );

            return $this->sendRequestThroughRouter($request);
        } catch (HttpResponseException $exception) {
            // Something short-circuited with a ready response — a guard calling
            // Response::throwResponse(), or a validation failure the handler
            // converted. Send it as-is.
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
            $this->container->resolve(ExceptionHandler::class)->report($exception);

            if ($this->app->isDebug()) {
                throw $exception;
            }
        }

        /**
         * Emit point — request.handled
         *
         * The response is final and nothing further will change it. Fires for
         * every outcome — a matched route, a 404, a validation short-circuit,
         * an unhandled throwable — because finish() is the single exit.
         * Payload: {@see RequestEvent}.
         */
        $this->eventLazy(
            CoreEvents::REQUEST_HANDLED,
            fn (): RequestEvent => new RequestEvent(
                $request->method(),
                $request->path(),
                $response->getStatusCode(),
            ),
        );

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
                /*
                 * Two marks, not one: the global middleware stack has already
                 * run by the time this closure is entered — session start and
                 * cookie decryption among it — and that is separate work from
                 * finding the route.
                 */
                BootProfile::mark('globalMiddleware');

                $resolvedRoute = $this->router->findMatchingRoute($request);

                BootProfile::mark('match');

                if (! $resolvedRoute) {
                    return $this->createUnmatchedResponse($request);
                }

                $this->router->substituteBindings($resolvedRoute);

                $request->setRouteResolver(static fn () => $resolvedRoute);

                BootProfile::mark('bindings');

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

            // Remembered, not re-resolved later: terminate() must run on the
            // same instance that handled the request, or anything it collected
            // on the way through is gone by the time it is asked to finish.
            if (method_exists($middleware, 'terminate')) {
                $this->terminableMiddleware[] = $middleware;
            }

            $stages[] = static fn (Request $request, callable $next): Response
                => $middleware->handle($request, $next, ...$parameters);
        }

        return Pipeline::make($this->container->resolve(ClassResolver::class))
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
        $routeMiddleware = array_merge(
            $resolvedRoute->getMiddleware(),
            $this->controllerMiddleware($resolvedRoute),
        );

        // Expansion depends only on the declared list and the group map, so it
        // is memoized by that list rather than repeated per request.
        // middlewareGroup() clears the cache when the map changes.
        $key = $routeMiddleware === [] ? '' : implode("\0", $routeMiddleware);
        if (isset($this->gatheredMiddlewareCache[$key])) {
            return $this->withoutExcluded(
                $this->gatheredMiddlewareCache[$key],
                $resolvedRoute->excludedMiddleware(),
            );
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

        $gathered = $this->sortMiddleware($gathered);

        /*
         * Not memoized with the rest: exclusions belong to one route, while
         * the cache is keyed by the declared list a hundred routes may share.
         */
        $this->gatheredMiddlewareCache[$key] = $gathered;

        return $this->withoutExcluded($gathered, $resolvedRoute->excludedMiddleware());
    }

    /**
     * The middleware a controller declares for the action being run.
     *
     * Read statically off the class, so the stack is assembled before the
     * controller is resolved — a controller that guards itself does not have
     * to be constructed to say so.
     *
     * @return array<int, string>
     */
    protected function controllerMiddleware(Route $resolvedRoute): array
    {
        $class  = $resolvedRoute->getControllerClass();
        $method = $resolvedRoute->getControllerMethod();

        if ($class === null || $method === null || ! is_subclass_of($class, HasMiddleware::class)) {
            return [];
        }

        $gathered = [];

        foreach ($class::middleware() as $declared) {
            if ($declared instanceof ControllerMiddleware) {
                if ($declared->appliesTo($method)) {
                    $gathered[] = $declared->middleware;
                }

                continue;
            }

            if (is_string($declared)) {
                $gathered[] = $declared;
            }
        }

        return $gathered;
    }

    /**
     * Remove the middleware a route asked to drop.
     *
     * Compared by resolved class, so excluding 'csrf' removes VerifyCsrfToken
     * however the group happened to name it.
     *
     * @param  array<int, string> $gathered
     * @param  array<int, string> $excluded
     * @return array<int, string>
     */
    protected function withoutExcluded(array $gathered, array $excluded): array
    {
        if ($excluded === []) {
            return $gathered;
        }

        $drop = [];

        foreach ($excluded as $name) {
            [$alias] = $this->parseMiddlewareName($name);
            $drop[$this->router->getMiddlewareAlias($alias) ?? $alias] = true;
        }

        return array_values(array_filter($gathered, function (string $name) use ($drop): bool {
            [$alias] = $this->parseMiddlewareName($name);

            return ! isset($drop[$this->router->getMiddlewareAlias($alias) ?? $alias]);
        }));
    }

    /**
     * Put the gathered middleware into an order that respects
     * {@see $middlewarePriority}.
     *
     * Only listed names move, and only past one another: an unlisted
     * middleware keeps the position the route gave it, because the route
     * author had a reason for it and this has none.
     *
     * @param  array<int, string> $middleware
     * @return array<int, string>
     */
    protected function sortMiddleware(array $middleware): array
    {
        if ($this->middlewarePriority === [] || count($middleware) < 2) {
            return $middleware;
        }

        $lastIndex = 0;
        $lastPriorityIndex = null;

        foreach ($middleware as $index => $name) {
            $priorityIndex = $this->middlewarePriorityIndex($name);

            if ($priorityIndex === null) {
                continue;
            }

            /*
             * Outranks one already passed, so it belongs above it. Move it and
             * start over: a single pass cannot settle a list that needs more
             * than one swap.
             */
            if ($lastPriorityIndex !== null && $priorityIndex < $lastPriorityIndex) {
                return $this->sortMiddleware($this->moveMiddleware($middleware, $index, $lastIndex));
            }

            $lastIndex = $index;
            $lastPriorityIndex = $priorityIndex;
        }

        return $middleware;
    }

    /**
     * Where a middleware name sits in the priority list, or null when it is
     * not listed. Aliases and 'alias:args' resolve to their class first, so
     * 'auth' and Authenticate::class are the same entry.
     */
    protected function middlewarePriorityIndex(string $name): ?int
    {
        [$alias] = $this->parseMiddlewareName($name);

        $class = $this->router->getMiddlewareAlias($alias) ?? $alias;

        $index = array_search($class, $this->middlewarePriority, true);

        return $index === false ? null : $index;
    }

    /**
     * Move the middleware at $from so it sits at $to, shifting the rest along.
     *
     * @param  array<int, string> $middleware
     * @return array<int, string>
     */
    protected function moveMiddleware(array $middleware, int $from, int $to): array
    {
        array_splice($middleware, $to, 0, [$middleware[$from]]);

        unset($middleware[$from + 1]);

        return array_values($middleware);
    }

    /**
     * Give a middleware a place in the priority order, optionally right after
     * one already in it.
     *
     * The core list names only Http middleware. A feature layer whose
     * middleware depends on one of them — an authenticator needing the session
     * — declares that here from its provider, rather than the kernel naming a
     * layer it should not know about.
     */
    public function addMiddlewarePriority(string $middleware, ?string $after = null): static
    {
        $this->middlewarePriority = array_values(
            array_filter($this->middlewarePriority, static fn (string $m): bool => $m !== $middleware)
        );

        $position = $after === null ? false : array_search($after, $this->middlewarePriority, true);

        if ($position === false) {
            $this->middlewarePriority[] = $middleware;
        } else {
            array_splice($this->middlewarePriority, $position + 1, 0, [$middleware]);
        }

        /* The gathered lists were sorted under the old order. */
        $this->gatheredMiddlewareCache = [];

        return $this;
    }

    /** @return array<int, class-string> */
    public function getMiddlewarePriority(): array
    {
        return $this->middlewarePriority;
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

        return $this->container->resolve($this->middlewareClassCache[$name]);
    }

    /** Dispatch the resolved route to its handler. */
    protected function dispatchToHandler(Route $resolvedRoute, Request $request): Response
    {
        // 1. Get the raw result from the Dispatcher
        $result = $this->routeDispatcher->dispatchToHandler($resolvedRoute, $request);

        /*
         * The handler has returned but a view it asked for has not been
         * rendered yet, so this separates the application's own work from the
         * view engine's — the two things a slow response is usually made of.
         */
        BootProfile::mark('handler');

        /**
         * Emit point — route.dispatched
         *
         * The handler has run and returned, before its result is turned into
         * a Response. Completes the trio the router starts: route.matched
         * (which route), route.dispatching (about to run it), route.dispatched
         * (it ran). Does not fire when the handler threw.
         * Payload: {@see RouteEvent}.
         */
        $this->eventLazy(
            RoutingEvents::DISPATCHED,
            fn (): RouteEvent => new RouteEvent(
                method: $request->method(),
                path: $request->path(),
                name: $resolvedRoute->getName(),
                type: $resolvedRoute->getType(),
                parameters: $resolvedRoute->parameters(),
            ),
        );

        // 2. Handle the "Decoupled View" (The DTO)
        if ($result instanceof ViewResponse) {
            $renderer = $this->container->resolve(Engine::class);
            $html = $renderer->render($result->template, $result->data);

            BootProfile::mark('render');

            return Response::html($html);
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

        $handler = $this->container->resolve(ExceptionHandler::class);

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

    /**
     * The response for a request that matched no route.
     *
     * A path that answers other verbs is a 405, not a 404 — and RFC 9110
     * requires an Allow header naming them, since that is the only way a
     * client learns what the path does accept. An OPTIONS request is answered
     * outright rather than refused, which is what makes a CORS preflight work.
     *
     * A router that cannot report its verbs still gets the old 404.
     */
    protected function createUnmatchedResponse(Request $request): Response
    {
        $allowed = $this->router instanceof ReportsAllowedMethods
            ? $this->router->allowedMethods($request->path())
            : [];

        if ($allowed === []) {
            return $this->createNotFoundResponse($request);
        }

        $allow = implode(', ', $allowed);

        if ($request->method() === 'OPTIONS') {
            return new Response('', 204, ['Allow' => $allow]);
        }

        return $this->renderException($request, new HttpException(
            405,
            "Method {$request->method()} not allowed for {$request->path()}",
            null,
            ['Allow' => $allow],
        ));
    }

    /** Create a 404 Not Found response. */
    protected function createNotFoundResponse(Request $request): Response
    {
        $message = "Route not found: {$request->method()} {$request->path()}";

        return $this->renderException($request, new HttpException(404, $message));
    }

    /**
     * Run cleanup tasks after the response has been sent.
     *
     * Three things, in order: middleware that declared a terminate(), the hooks
     * providers registered, and the application's own terminating callbacks.
     *
     * Middleware is included because the hooks alone only served the framework —
     * a provider could register one, but an application's middleware had no way
     * to defer work past send(), which is the whole reason to write one.
     *
     * Every one is isolated. The response is already on the wire, so a failure
     * here cannot be reported to the client and must not stop the rest of the
     * cleanup; it goes to the exception handler instead.
     */
    public function terminate(Request $request, Response $response): void
    {
        foreach ($this->terminableMiddleware as $middleware) {
            $this->safely(fn () => $middleware->terminate($request, $response));
        }

        $this->terminableMiddleware = [];

        foreach ($this->terminatingHooks as $hook) {
            $this->safely(fn () => $hook($request, $response));
        }

        $this->safely(fn () => $this->app->terminate());
    }

    /**
     * Middleware instances from this request that expose a terminate().
     *
     * Cleared at the end of terminate() so a worker does not carry the previous
     * request's middleware into the next one.
     *
     * @var array<int, object>
     */
    private array $terminableMiddleware = [];

    /** Run a cleanup step, reporting a failure rather than letting it end the rest. */
    private function safely(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            try {
                $this->container->resolve(ExceptionHandler::class)->report($exception);
            } catch (Throwable) {
                // Nothing left to report through; the response has been sent.
            }
        }
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
