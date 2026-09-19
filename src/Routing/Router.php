<?php

namespace Nitro\Routing;

use Closure;
use InvalidArgumentException;
use Nitro\Exceptions\HttpException;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\ExtendableRouter;
use Nitro\Routing\Contracts\ReportsAllowedMethods;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Contracts\SignsUrls;
use Nitro\Routing\Events\RouteEvent;
use Nitro\Routing\Events\RoutingEvents;
use Nitro\Routing\Concerns\CompilesRoutePatterns;
use Nitro\Routing\Concerns\GeneratesUrls;
use Nitro\Routing\Concerns\GeneratesSignedUrls;
use Nitro\Routing\Concerns\ManagesRouteCache;
use Nitro\Support\Logger;
use Nitro\Support\Macroable;
use Nitro\Support\Str;
use RuntimeException;


/**
 * The NitroPHP HTTP router.
 *
 * Registers routes (verbs, groups, views), stores them in structures
 * optimized for matching, and resolves an incoming request to a {@see Route}
 * value object. Cross-cutting behaviour (pattern compilation, URL generation,
 * cache (de)serialization) lives in the Concerns traits to keep this class
 * focused on registration and matching.
 *
 * The router names no feature layer, and there are two seams for keeping it
 * that way. A layer adds a registration verb through {@see ExtendableRouter}
 * (the router is {@see Macroable}), and a whole new kind of route through
 * {@see RouteTypes} — so the four types below are the only ones this class
 * will ever need to know.
 */
class Router implements RouterInterface, ExtendableRouter, ReportsAllowedMethods, SignsUrls, ReceivesDispatcher
{
    use CompilesRoutePatterns,
        ManagesRouteCache,
        GeneratesUrls,
        GeneratesSignedUrls,
        DispatchesEvents,
        Macroable;

    /**
     * Register a route-registration verb, as {@see ExtendableRouter} describes.
     *
     * Macros are this router's way of storing one, but that is an implementation
     * detail: a feature layer asks the router it was given to extend itself, and
     * never names a class to call macro() on statically.
     */
    public function extend(string $name, Closure $handler): void
    {
        static::macro($name, $handler);
    }

    /** Original unified storage (maintained for backward compatibility) */
    protected array $routes = [];

    /** Performance optimization: Separated storage */
    protected array $staticRoutes = [];
    protected array $dynamicRoutes = [];

    /**
     * Dynamic routes bucketed by first URL segment so route matching only
     * scans routes whose static prefix matches the incoming path. Routes
     * whose first segment is itself dynamic ({id}) go in the wildcard bucket
     * keyed by '*' and are always considered.
     *
     * Shape: [method => [bucket => [routeData, ...]]]
     */
    protected array $dynamicRoutesByPrefix = [];

    /** Namespace management */
    protected string $namespace = '';

    /** Group state management */
    protected array $groupStack = [];
    protected string $currentPrefix = '';
    protected array $currentMiddleware = [];
    protected string $currentNamespace = '';
    protected string $currentName = '';
    protected string $currentDomain = '';

    /** Whether the enclosing group asked for scoped bindings. */
    protected bool $currentScopeBindings = false;

    /**
     * Routes constrained to a host, keyed by method.
     *
     * Held apart from the static and dynamic structures because those are keyed
     * by path alone: two routes may share a path and differ only by domain, and
     * the O(1) map has room for one of them. Matching consults this list first
     * and falls through to the path-only structures when no host matches.
     *
     * Shape: [method => [['domain' => …, 'host_regex' => …, 'path' => …,
     *                     'path_regex' => …|null, 'param_names' => [...],
     *                     'handler' => [...]], ...]]
     */
    protected array $domainRoutes = [];

    /**
     * Routes replaced by a later registration at the same method and path,
     * keyed by "method|path". Consulted when a route moves into the
     * host-matched list and frees the path again.
     */
    protected array $displacedRoutes = [];

    /** Whether to log every route match (off in production by default). */
    protected bool $debugLogging = false;

    /**
     * Short-hand middleware aliases: [alias => middleware class]. Feature
     * providers register their own via aliasMiddleware() in boot().
     *
     * @var array<string, class-string>
     */
    protected array $middlewareAliases = [];

    /**
     * Initialize the router.
     *
     * Reads the default controller namespace and debug-logging flag from
     * config.
     */
    public function __construct(
        protected ConfigRepository $config,
        protected RouteTypes $routeTypes = new RouteTypes(),
    ) {
        $this->namespace = $config->get('app.controllers_namespace');
        $this->debugLogging = (bool) $config->get('app.debug');
    }

    /**
     * Register a short-hand name for a route middleware. Feature service
     * providers call this in boot() to wire their middleware without the core
     * kernel ever naming them.
     */
    public function aliasMiddleware(string $name, string $class): static
    {
        $this->middlewareAliases[$name] = $class;

        return $this;
    }

    /**
     * Resolve a middleware alias to its class, or null if the name isn't a
     * registered alias (the kernel then treats it as a direct class name).
     */
    public function getMiddlewareAlias(string $name): ?string
    {
        return $this->middlewareAliases[$name] ?? null;
    }

    /**
     * Every registered middleware alias, as [name => class].
     *
     * Used by the kernel to name the alternatives when a route asks for an
     * alias that does not exist, and by `nitro lifecycle` to show what a group
     * or a route middleware name will actually resolve to.
     *
     * @return array<string, class-string>
     */
    public function getMiddlewareAliases(): array
    {
        return $this->middlewareAliases;
    }

    /**
     * Register a route that responds to GET requests.
     */
    public function get(string $path, $handler): static
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a route that responds to POST requests.
     */
    public function post(string $path, $handler): static
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a route that responds to PUT requests.
     */
    public function put(string $path, $handler): static
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a route that responds to DELETE requests.
     */
    public function delete(string $path, $handler): static
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a route that responds to PATCH requests.
     */
    public function patch(string $path, $handler): static
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register a single handler for several HTTP methods at once.
     */
    public function methods(array $methods, string $path, $handler): static
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }

    /**
     * Register a handler for several HTTP methods (alias for {@see methods()}).
     */
    public function match(array $methods, string $path, $handler): static
    {
        return $this->methods($methods, $path, $handler);
    }

    /**
     * Register a handler for every common HTTP method (GET through OPTIONS).
     */
    public function any(string $path, $handler): static
    {
        return $this->methods(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $path, $handler);
    }

    /**
     * Register the seven RESTful routes for a resource controller, Laravel-style:
     *
     *   Route::resource('photos', PhotoController::class);
     *
     * Produces index/create/store/show/edit/update/destroy with the matching
     * route names (photos.index, …) and a singularised wildcard ({photo}), so
     * route-model binding works: show(Photo $photo).
     *
     * Pass ['only' => [...]] or ['except' => [...]] to limit the verbs.
     */
    public function resource(string $name, string $controller, array $options = []): static
    {
        $name  = trim($name, '/');
        $base  = '/' . $name;
        $param = $options['parameter'] ?? $this->singularize($name);
        $wild  = $base . '/{' . $param . '}';
        $named = str_replace('/', '.', $name);

        /*
         * update takes PUT and PATCH both: a full replacement and a partial
         * one are the same controller action, and an HTML form that spoofs
         * PATCH would otherwise 405 against a PUT-only route.
         */
        $actions = [
            'index'   => ['GET',            $base],
            'create'  => ['GET',            $base . '/create'],
            'store'   => ['POST',           $base],
            'show'    => ['GET',            $wild],
            'edit'    => ['GET',            $wild . '/edit'],
            'update'  => [['PUT', 'PATCH'], $wild],
            'destroy' => ['DELETE',         $wild],
        ];

        if (!empty($options['only'])) {
            $actions = array_intersect_key($actions, array_flip((array) $options['only']));
        }
        if (!empty($options['except'])) {
            $actions = array_diff_key($actions, array_flip((array) $options['except']));
        }

        foreach ($actions as $action => [$methods, $path]) {
            foreach ((array) $methods as $index => $method) {
                $route = $this->addRoute($method, $path, [$controller, $action]);

                /*
                 * Only the first verb carries the name: two routes sharing one
                 * name would have the second overwrite the first in the named
                 * table, and route('posts.update') must keep meaning PUT.
                 */
                if ($index === 0) {
                    $route->name("{$named}.{$action}");
                }
            }
        }

        return $this;
    }

    /**
     * The parameter name for a resource's member routes (photos → photo).
     *
     * Delegates to {@see Str::singular()}. It used to be rtrim($name, 's'),
     * which strips every trailing s at once: Route::resource('address', …)
     * registered /address/{addre}, and 'status' gave {statu}.
     *
     * Override an unhappy result with the 'parameter' option rather than
     * teaching the inflector a one-off word.
     */
    protected function singularize(string $name): string
    {
        $segment = str_contains($name, '/') ? substr(strrchr($name, '/'), 1) : $name;

        return Str::singular($segment) ?: $segment;
    }

    /**
     * Register a GET route that renders a view with the given data, without
     * needing a controller or closure.
     */
    public function view(string $path, string $viewName, array $data = []): static
    {
        $handler = [
            'type' => 'view',
            'view' => $viewName,
            'data' => $data
        ];

        $fullPath = $this->buildFullPath($path);
        $handler['middleware'] = $this->currentMiddleware;

        $this->storeRoute('GET', $fullPath, $handler);

        return $this;
    }

    /**
     * Register a group of routes that share a prefix, middleware, namespace
     * and/or name.
     *
     * The current group state is pushed before the callback runs and restored
     * afterwards, so nested groups compose correctly.
     */
    public function group(array $attributes, Closure $callback): static
    {
        // Push current state to stack
        $this->groupStack[] = [
            'prefix' => $this->currentPrefix,
            'middleware' => $this->currentMiddleware,
            'namespace' => $this->currentNamespace,
            'name' => $this->currentName,
            'domain' => $this->currentDomain,
            'scopeBindings' => $this->currentScopeBindings,
        ];

        // Apply group attributes
        $this->updateGroupAttributes($attributes);

        // Execute callback with group context
        $callback($this);

        // Restore previous state
        $previous = array_pop($this->groupStack);
        $this->currentPrefix = $previous['prefix'];
        $this->currentMiddleware = $previous['middleware'];
        $this->currentNamespace = $previous['namespace'];
        $this->currentName = $previous['name'];
        $this->currentDomain = $previous['domain'];
        $this->currentScopeBindings = $previous['scopeBindings'];

        return $this;
    }

    /**
     * Set the path prefix applied to subsequently registered routes.
     */
    public function prefix(string $prefix): static
    {
        $this->currentPrefix = $this->buildPrefix($this->currentPrefix, $prefix);
        return $this;
    }

    /**
     * Put middleware on the route that was just registered.
     *
     *     Route::get('/invoice/{order}', ...)->middleware('auth')->name('invoice');
     *
     * On that route, not on everything after it. Appending to the group's stack
     * here would mean one ->middleware('auth') quietly locking every route
     * declared below it for the rest of the file — a leak that surfaces as a
     * public page redirecting to the login screen, with nothing near the page
     * to explain why.
     *
     * Middleware for a group goes in the group's own attributes:
     *
     *     Route::group(['middleware' => 'auth'], function () { ... });
     *
     * Accepts a single name or an array of names.
     *
     * @throws RuntimeException When called before any route has been defined.
     */
    public function middleware($middleware): static
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        if (! $this->lastRoute) {
            throw new RuntimeException(
                'No route to apply middleware to. Call middleware() immediately after defining a route, '
                . "or pass it to a group: Route::group(['middleware' => '…'], …)."
            );
        }

        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];
        $existing = $this->routes[$method][$path]['middleware'] ?? [];

        $this->setOnLastRoute('middleware', array_values(array_unique(array_merge($existing, $middleware))));

        return $this;
    }

    /**
     * Write one key onto the most recently registered route, everywhere it is
     * stored.
     *
     * A dynamic route is denormalised into three structures — the unified map,
     * the flat dynamic list, and the prefix buckets that matching actually
     * reads — each a by-value copy. Miss one and the matched route loses the
     * change in whichever code path reads that copy.
     */
    protected function setOnLastRoute(string $key, mixed $value): void
    {
        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];

        $domainRoute = &$this->lastDomainRoute();

        if ($domainRoute !== null) {
            $domainRoute['handler'][$key] = $value;

            return;
        }

        unset($domainRoute);

        $this->routes[$method][$path][$key] = $value;

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path][$key] = $value;

            return;
        }

        if (isset($this->dynamicRoutes[$method])) {
            foreach ($this->dynamicRoutes[$method] as &$route) {
                if ($route['pattern'] === $path) {
                    $route['handler'][$key] = $value;
                    break;
                }
            }
            unset($route);
        }

        if (isset($this->dynamicRoutesByPrefix[$method])) {
            foreach ($this->dynamicRoutesByPrefix[$method] as &$bucket) {
                foreach ($bucket as &$route) {
                    if ($route['pattern'] === $path) {
                        $route['handler'][$key] = $value;
                        break;
                    }
                }
                unset($route);
            }
            unset($bucket);
        }
    }

    /**
     * Resolve a nested model through its parent's relation.
     *
     *   Route::get('/posts/{post}/comments/{comment}', …)->scopeBindings();
     *
     * Without it, /posts/1/comments/99 returns comment 99 whatever post it
     * belongs to. With it, a comment from another post is a 404.
     */
    public function scopeBindings(): static
    {
        $this->setOnLastRoute('scoped', true);

        return $this;
    }

    /**
     * Begin a group, collecting its attributes fluently.
     *
     *   Route::withMiddleware('auth')->prefix('admin')->group(fn () => …);
     *
     * Named apart from {@see middleware()}, which applies to the route just
     * registered: reusing that name would make this silently attach to the
     * previous route whenever one existed.
     *
     * @param string|array<int, string> $middleware
     */
    public function withMiddleware(string|array $middleware): RouteRegistrar
    {
        return (new RouteRegistrar($this))->middleware($middleware);
    }

    /** Begin a group under a path prefix. See {@see withMiddleware()}. */
    public function withPrefix(string $prefix): RouteRegistrar
    {
        return (new RouteRegistrar($this))->prefix($prefix);
    }

    /**
     * Drop middleware the route's groups would otherwise contribute.
     *
     *   Route::post('/hooks/stripe', …)->middleware('web')->withoutMiddleware('csrf');
     *
     * A group is all-or-nothing without this: a webhook that must skip CSRF
     * but keep sessions and cookies would have to leave 'web' and re-list
     * everything it wanted.
     *
     * @param string|array<int, string> $middleware Aliases or class names.
     */
    public function withoutMiddleware(string|array $middleware): static
    {
        $this->setOnLastRoute('without_middleware', (array) $middleware);

        return $this;
    }

    /** Bind soft-deleted models too, rather than treating them as missing. */
    public function withTrashed(bool $withTrashed = true): static
    {
        $this->setOnLastRoute('with_trashed', $withTrashed);

        return $this;
    }

    /**
     * Answer with this instead of a 404 when a bound model is not found.
     *
     * The callable is stored on the route, so a route using it cannot be
     * cached — the same all-or-nothing rule a closure handler falls under.
     */
    public function missing(callable $handler): static
    {
        $this->setOnLastRoute('missing', $handler);

        return $this;
    }

    // ─── Explicit model binding ───────────────────────────────────────────

    /**
     * Resolvers for route parameters, keyed by parameter name.
     *
     * @var array<string, Closure>
     */
    protected array $binders = [];

    /**
     * Resolve a route parameter through a callback.
     *
     *   Route::bind('user', fn ($value) => User::where('slug', $value)->firstOrFail());
     *
     * The callback receives the raw URL segment and the matched route, and its
     * return value replaces the segment before the handler is called. Applies
     * whether or not the handler type-hints the parameter.
     */
    public function bind(string $key, Closure $binder): static
    {
        $this->binders[$this->normalizeBindingKey($key)] = $binder;

        return $this;
    }

    /**
     * Resolve a route parameter to a model.
     *
     *   Route::model('user', User::class);
     *
     * Looks the value up by primary key. A missing record raises 404 unless
     * $missing is given, in which case its return value is used instead.
     *
     * @param class-string  $class
     * @param Closure|null  $missing Called with the value when nothing is found.
     */
    public function model(string $key, string $class, ?Closure $missing = null): static
    {
        return $this->bind($key, static function ($value) use ($class, $missing) {
            if ($value === null) {
                return null;
            }

            $model = $class::find($value);

            if ($model !== null) {
                return $model;
            }

            if ($missing !== null) {
                return $missing($value);
            }

            throw new HttpException(404, "No query results for model [{$class}] {$value}.");
        });
    }

    /** Whether a resolver is registered for a parameter name. */
    public function hasBinding(string $key): bool
    {
        return isset($this->binders[$this->normalizeBindingKey($key)]);
    }

    /** The resolver registered for a parameter name, or null. */
    public function getBindingCallback(string $key): ?Closure
    {
        return $this->binders[$this->normalizeBindingKey($key)] ?? null;
    }

    /** @return array<string, Closure> */
    public function getBinders(): array
    {
        return $this->binders;
    }

    /**
     * Replace a matched route's parameters with their resolved values.
     *
     * Only parameters with a registered resolver are touched; the rest are
     * left as the raw URL segments, for implicit binding to handle by type
     * when the handler's arguments are resolved.
     */
    public function substituteBindings(Route $route): Route
    {
        if ($this->binders === []) {
            return $route;
        }

        foreach ($route->parameters() as $name => $value) {
            $binder = $this->getBindingCallback($name);

            if ($binder === null || ! is_scalar($value)) {
                continue;
            }

            $route->setParameter($name, $binder($value, $route));
        }

        return $route;
    }

    /**
     * Binding keys are matched on the parameter name, so snake_case and
     * camelCase spellings of the same segment resolve to one resolver.
     */
    private function normalizeBindingKey(string $key): string
    {
        return str_replace(['-', '_'], '', strtolower($key));
    }

    // ─── Domains ──────────────────────────────────────────────────────────

    /**
     * Constrain the last registered route to a host.
     *
     *   Route::get('/', …)->domain('admin.example.com');
     *   Route::get('/', …)->domain('{account}.example.com');
     *
     * Placeholders in the host are captured and merged into the route's
     * parameters ahead of the path's own, so a handler can take the subdomain
     * as an argument. The port is ignored when comparing.
     *
     * The route is moved out of the path-keyed structures, which cannot hold
     * two routes that differ only by host.
     */
    public function domain(string $domain): static
    {
        $this->requireLastRoute('domain');

        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];

        $routeData = $this->routes[$method][$path] ?? null;

        if ($routeData === null) {
            return $this;
        }

        $routeData['domain'] = $domain;
        $this->routes[$method][$path] = $routeData;

        $this->forgetPathOnlyRoute($method, $path);

        $this->domainRoutes[$method][] = [
            'domain'       => $domain,
            'host_regex'   => $this->compileHostPattern($domain),
            'host_params'  => $this->extractParameterNames($domain),
            'path'         => $path,
            'path_regex'   => $this->hasParameters($path)
                ? $this->compilePattern($path, $routeData['wheres'] ?? [])
                : null,
            'param_names'  => $this->extractParameterNames($path),
            'handler'      => $routeData,
        ];

        // Further chaining (->name(), ->where()) must follow the route into the
        // host-matched list: the path-keyed entry is about to be handed back to
        // whichever route this one displaced.
        $this->lastRoute['domain_index'] = array_key_last($this->domainRoutes[$method]);

        $this->restoreDisplacedRoute($method, $path);

        return $this;
    }

    /**
     * The host-matched entry the chain is currently pointing at, by reference,
     * or null when the last route is not host-constrained.
     */
    protected function &lastDomainRoute(): ?array
    {
        $none = null;

        if (! isset($this->lastRoute['domain_index'])) {
            return $none;
        }

        $method = $this->lastRoute['method'];
        $index = $this->lastRoute['domain_index'];

        if (! isset($this->domainRoutes[$method][$index])) {
            return $none;
        }

        return $this->domainRoutes[$method][$index];
    }

    /**
     * Put back a route this one replaced when it was registered.
     *
     * Registering two routes at the same method and path overwrites the first.
     * Once the second moves into the host-matched list the path is free again,
     * and the original — which has no domain and is still reachable — belongs
     * back in the path-keyed structures.
     */
    protected function restoreDisplacedRoute(string $method, string $path): void
    {
        $key = $method . '|' . $path;

        if (! isset($this->displacedRoutes[$key])) {
            return;
        }

        $displaced = $this->displacedRoutes[$key];
        unset($this->displacedRoutes[$key]);

        // storeRoute() marks what it stores as the route to chain onto, which
        // would redirect a ->name() after ->domain() to the wrong route.
        $lastRoute = $this->lastRoute;
        $this->storeRoute($method, $path, $displaced);
        $this->lastRoute = $lastRoute;
    }

    /**
     * Compile a host pattern into an anchored, case-insensitive regex.
     *
     * Literal text is quoted so dots match dots; each placeholder captures one
     * label, which cannot itself contain a dot.
     */
    protected function compileHostPattern(string $domain): string
    {
        $regex = '';
        $offset = 0;

        preg_match_all('#\{([^}]+)\}#', $domain, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$placeholder, $position]) {
            $regex .= preg_quote(substr($domain, $offset, $position - $offset), '#') . '([^.]+)';
            $offset = $position + strlen($placeholder);
        }

        $regex .= preg_quote(substr($domain, $offset), '#');

        return '#^' . $regex . '$#i';
    }

    /**
     * Remove a route from the path-keyed lookup structures.
     *
     * Used when a route gains a domain and moves into the host-matched list.
     */
    protected function forgetPathOnlyRoute(string $method, string $path): void
    {
        unset(
            $this->staticRoutes[$method][$path],
            $this->compiledPatterns[$method][$path]
        );

        if (isset($this->dynamicRoutes[$method])) {
            $this->dynamicRoutes[$method] = array_values(array_filter(
                $this->dynamicRoutes[$method],
                static fn (array $route) => $route['pattern'] !== $path
            ));
        }

        if (isset($this->dynamicRoutesByPrefix[$method])) {
            foreach ($this->dynamicRoutesByPrefix[$method] as $bucket => $routes) {
                $this->dynamicRoutesByPrefix[$method][$bucket] = array_values(array_filter(
                    $routes,
                    static fn (array $route) => $route['pattern'] !== $path
                ));
            }
        }
    }

    /**
     * Resolve a request against the host-constrained routes.
     *
     * @return array{handler: array<string, mixed>, parameters: array<mixed>}|null
     */
    protected function findDomainRoute(string $method, string $path, string $host): ?array
    {
        if (empty($this->domainRoutes[$method])) {
            return null;
        }

        $host = explode(':', $host)[0];

        foreach ($this->domainRoutes[$method] as $route) {
            if (! preg_match($route['host_regex'], $host, $hostMatches)) {
                continue;
            }

            array_shift($hostMatches);

            $parameters = [];

            foreach ($route['host_params'] as $index => $name) {
                if (array_key_exists($index, $hostMatches)) {
                    $parameters[$name] = $hostMatches[$index];
                }
            }

            if ($route['path_regex'] === null) {
                if ($route['path'] !== $path) {
                    continue;
                }

                return ['handler' => $route['handler'], 'parameters' => $parameters];
            }

            if (! preg_match($route['path_regex'], $path, $pathMatches)) {
                continue;
            }

            array_shift($pathMatches);

            foreach ($pathMatches as $index => $value) {
                $parameters[$index] = $value;
            }

            foreach ($route['param_names'] as $index => $name) {
                if (array_key_exists($index, $pathMatches)) {
                    $parameters[$name] = $pathMatches[$index];
                }
            }

            return ['handler' => $route['handler'], 'parameters' => $parameters];
        }

        return null;
    }

    // ─── Current route ────────────────────────────────────────────────────

    protected ?Route $currentRoute = null;
    protected ?Request $currentRequest = null;

    /** The route matched for the request being handled, or null. */
    public function current(): ?Route
    {
        return $this->currentRoute;
    }

    public function getCurrentRoute(): ?Route
    {
        return $this->currentRoute;
    }

    public function getCurrentRequest(): ?Request
    {
        return $this->currentRequest;
    }

    public function currentRouteName(): ?string
    {
        return $this->currentRoute?->getName();
    }

    /** Whether the current route's name matches any of the given patterns. */
    public function currentRouteNamed(string ...$patterns): bool
    {
        $name = $this->currentRouteName();

        if ($name === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($this->nameMatches($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Alias of {@see currentRouteNamed()}. */
    public function is(string ...$patterns): bool
    {
        return $this->currentRouteNamed(...$patterns);
    }

    /** "Controller@method" for the current route, or null for a closure. */
    public function currentRouteAction(): ?string
    {
        $route = $this->currentRoute;

        if ($route === null || ! $route->isController()) {
            return null;
        }

        return $route->getControllerClass() . '@' . $route->getControllerMethod();
    }

    public function currentRouteUses(): ?string
    {
        return $this->currentRouteAction();
    }

    /** Whether a route with this name is registered. */
    public function has(string ...$names): bool
    {
        foreach ($names as $name) {
            if ($this->findRouteDataByName($name) === null) {
                return false;
            }
        }

        return $names !== [];
    }

    /** @return array<string, mixed>|null */
    protected function findRouteDataByName(string $name): ?array
    {
        foreach ($this->routes as $paths) {
            foreach ($paths as $routeData) {
                if (($routeData['name'] ?? null) === $name) {
                    return $routeData;
                }
            }
        }

        foreach ($this->domainRoutes as $routes) {
            foreach ($routes as $route) {
                if (($route['handler']['name'] ?? null) === $name) {
                    return $route['handler'];
                }
            }
        }

        return null;
    }

    private function nameMatches(string $pattern, string $name): bool
    {
        if ($pattern === $name) {
            return true;
        }

        if (! str_contains($pattern, '*')) {
            return false;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match('#^' . $regex . '\z#u', $name);
    }

    // ─── Redirects and fallback ───────────────────────────────────────────

    /** A route that redirects straight to another URI. */
    public function redirect(string $from, string $to, int $status = 302): static
    {
        return $this->any($from, static function () use ($to, $status) {
            return redirect($to, $status);
        });
    }

    /** A permanent (301) redirect route. */
    public function permanentRedirect(string $from, string $to): static
    {
        return $this->redirect($from, $to, 301);
    }

    /**
     * The handler used when nothing else matches.
     *
     * Registered as a catch-all rather than special-cased in matching, so it
     * participates in the ordinary middleware pipeline.
     */
    public function fallback($handler): static
    {
        $this->fallbackHandler = $handler;

        return $this;
    }

    /** @var mixed */
    protected $fallbackHandler = null;

    public function getFallback()
    {
        return $this->fallbackHandler;
    }

    public function hasFallback(): bool
    {
        return $this->fallbackHandler !== null;
    }

    // ─── Resource variants ────────────────────────────────────────────────

    /** A resource without the create/edit form routes. */
    public function apiResource(string $name, string $controller, array $options = []): static
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);

        return $this->resource($name, $controller, $options);
    }

    /**
     * Register a resource that has no id — /profile, /settings.
     *
     * The member routes drop the {parameter} the plural form carries, and
     * there is no index or store: a singleton already exists.
     *
     * @param array{only?: array<int, string>, except?: array<int, string>} $options
     */
    public function singleton(string $name, string $controller, array $options = []): static
    {
        $name  = trim($name, '/');
        $base  = '/' . $name;
        $named = str_replace('/', '.', $name);

        $actions = [
            'show'    => ['GET',            $base],
            'edit'    => ['GET',            $base . '/edit'],
            'update'  => [['PUT', 'PATCH'], $base],
            'destroy' => ['DELETE',         $base],
        ];

        if (!empty($options['only'])) {
            $actions = array_intersect_key($actions, array_flip((array) $options['only']));
        }
        if (!empty($options['except'])) {
            $actions = array_diff_key($actions, array_flip((array) $options['except']));
        }

        foreach ($actions as $action => [$methods, $path]) {
            foreach ((array) $methods as $index => $method) {
                $route = $this->addRoute($method, $path, [$controller, $action]);

                if ($index === 0) {
                    $route->name("{$named}.{$action}");
                }
            }
        }

        return $this;
    }

    /** A singleton with no edit form, the way apiResource has no create. */
    public function apiSingleton(string $name, string $controller, array $options = []): static
    {
        $options['except'] = array_merge($options['except'] ?? [], ['edit']);

        return $this->singleton($name, $controller, $options);
    }

    /**
     * Register several singletons at once.
     *
     * @param array<string, string> $singletons name => controller
     */
    public function singletons(array $singletons, array $options = []): static
    {
        foreach ($singletons as $name => $controller) {
            $this->singleton($name, $controller, $options);
        }

        return $this;
    }

    /**
     * Register several resources at once.
     *
     * @param array<string, string> $resources name => controller
     */
    public function resources(array $resources, array $options = []): static
    {
        foreach ($resources as $name => $controller) {
            $this->resource($name, $controller, $options);
        }

        return $this;
    }

    /** @param array<string, string> $resources name => controller */
    public function apiResources(array $resources, array $options = []): static
    {
        foreach ($resources as $name => $controller) {
            $this->apiResource($name, $controller, $options);
        }

        return $this;
    }

    /** Register an OPTIONS route. */
    public function options(string $path, $handler): static
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    // ─── Parameter constraints ────────────────────────────────────────────

    /**
     * Constrain one or more of the last route's parameters.
     *
     *   Route::get('/posts/{id}', …)->where('id', '[0-9]+');
     *   Route::get('/{a}/{b}', …)->where(['a' => '\d+', 'b' => '[a-z]+']);
     *
     * Matching reads the compiled regex rather than the constraint list, so the
     * route is recompiled here for the change to take effect.
     *
     * @param string|array<string, string> $name
     */
    public function where(string|array $name, ?string $expression = null): static
    {
        $wheres = is_array($name) ? $name : [$name => (string) $expression];

        $this->requireLastRoute('where');

        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];

        $domainRoute = &$this->lastDomainRoute();

        if ($domainRoute !== null) {
            $merged = array_merge($domainRoute['handler']['wheres'] ?? [], $wheres);
            $domainRoute['handler']['wheres'] = $merged;
            $domainRoute['path_regex'] = $this->hasParameters($path)
                ? $this->compilePattern($path, $merged)
                : null;

            return $this;
        }

        unset($domainRoute);

        $existing = $this->routes[$method][$path]['wheres'] ?? [];

        $merged = array_merge($existing, $wheres);
        $this->setOnLastRoute('wheres', $merged);

        $this->compiledPatterns[$method][$path] = $this->compilePattern($path, $merged);

        return $this;
    }

    /** Constrain parameters to digits. */
    public function whereNumber(string|array $parameters): static
    {
        return $this->whereEach($parameters, '[0-9]+');
    }

    /** Constrain parameters to letters. */
    public function whereAlpha(string|array $parameters): static
    {
        return $this->whereEach($parameters, '[a-zA-Z]+');
    }

    /** Constrain parameters to letters and digits. */
    public function whereAlphaNumeric(string|array $parameters): static
    {
        return $this->whereEach($parameters, '[a-zA-Z0-9]+');
    }

    /** Constrain parameters to a UUID. */
    public function whereUuid(string|array $parameters): static
    {
        return $this->whereEach(
            $parameters,
            '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'
        );
    }

    /** Constrain parameters to a ULID. */
    public function whereUlid(string|array $parameters): static
    {
        return $this->whereEach($parameters, '[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}');
    }

    /**
     * Constrain a parameter to one of a fixed set of values.
     *
     * @param array<int, string> $values
     */
    public function whereIn(string $parameter, array $values): static
    {
        $alternation = implode('|', array_map(
            static fn (string $value) => preg_quote($value, '#'),
            $values
        ));

        return $this->where($parameter, $alternation);
    }

    /** @param string|array<int, string> $parameters */
    protected function whereEach(string|array $parameters, string $expression): static
    {
        $wheres = [];

        foreach ((array) $parameters as $parameter) {
            $wheres[$parameter] = $expression;
        }

        return $this->where($wheres);
    }

    /**
     * Constrain a parameter name across every route, present and future.
     *
     * A route's own where() overrides this.
     */
    public function pattern(string $name, string $expression): static
    {
        $this->globalPatterns[$name] = $expression;
        $this->recompileAllPatterns();

        return $this;
    }

    /** @param array<string, string> $patterns */
    public function patterns(array $patterns): static
    {
        $this->globalPatterns = array_merge($this->globalPatterns, $patterns);
        $this->recompileAllPatterns();

        return $this;
    }

    /** @return array<string, string> */
    public function getPatterns(): array
    {
        return $this->globalPatterns;
    }

    /**
     * Rebuild every compiled pattern.
     *
     * A global constraint can be declared after routes are registered, and the
     * routes already compiled would otherwise keep the old regex.
     */
    protected function recompileAllPatterns(): void
    {
        foreach ($this->compiledPatterns as $method => $patterns) {
            foreach (array_keys($patterns) as $path) {
                $this->compiledPatterns[$method][$path] = $this->compilePattern(
                    $path,
                    $this->routes[$method][$path]['wheres'] ?? []
                );
            }
        }
    }

    /** @throws RuntimeException When there is no route to modify. */
    protected function requireLastRoute(string $method): void
    {
        if (! $this->lastRoute) {
            throw new RuntimeException(
                "No route to apply {$method}() to. Call it immediately after defining a route."
            );
        }
    }

    /**
     * Set the controller namespace applied to subsequently registered routes.
     */
    public function namespace(string $namespace): static
    {
        $this->currentNamespace = $this->buildNamespace($this->currentNamespace, $namespace);
        return $this;
    }

    /**
     * Normalize a handler and store it under the given method and path,
     * applying the current group's middleware.
     */
    protected function addRoute(string $method, string $path, $handler): static
    {
        $fullPath = $this->buildFullPath($path);
        $routeData = $this->parseHandler($handler);
        $routeData['middleware'] = $this->currentMiddleware;

        $bindingFields = $this->extractBindingFields($fullPath);

        if ($bindingFields !== []) {
            $routeData['binding_fields'] = $bindingFields;
        }

        /* Declaration order, which is what a scoped child resolves through. */
        $order = $this->extractParameterNames($fullPath);

        if ($order !== []) {
            $routeData['param_order'] = $order;
        }

        if ($this->currentScopeBindings) {
            $routeData['scoped'] = true;
        }

        $this->storeRoute($method, $fullPath, $routeData);

        if ($this->currentDomain !== '') {
            $this->domain($this->currentDomain);
        }

        return $this;
    }

    /**
     * Store a route in the optimized lookup structures.
     *
     * Static routes go into a method/path map for O(1) lookup; parameterized
     * routes are pre-compiled to a regex and bucketed by first segment so
     * matching only scans plausible candidates.
     */
    protected function storeRoute(string $method, string $fullPath, array $routeData): void
    {
        // A second route at the same method and path replaces the first here,
        // but the two may differ only by host. Keep the displaced one so that
        // domain(), which runs after registration, can put it back once the
        // new route moves into the host-matched list.
        if (isset($this->routes[$method][$fullPath])) {
            $this->displacedRoutes[$method . '|' . $fullPath] = $this->routes[$method][$fullPath];
        }

        // Store in original unified array (backward compatibility)
        $this->routes[$method][$fullPath] = $routeData;

        // Store reference for potential chaining
        $this->lastRoute = ['method' => $method, 'path' => $fullPath];

        // Detect if route has parameters
        if ($this->hasParameters($fullPath)) {
            // Dynamic route - store separately with pre-compiled pattern
            if (!isset($this->dynamicRoutes[$method])) {
                $this->dynamicRoutes[$method] = [];
            }

            $route = [
                'pattern'     => $fullPath,
                'handler'     => $routeData,
                'param_names' => $this->extractParameterNames($fullPath),
            ];

            $this->dynamicRoutes[$method][] = $route;

            // Bucket by first URL segment so findDynamicRoute can skip the
            // bulk of routes that can't possibly match. {placeholder}-first
            // routes land in the wildcard bucket and are always checked.
            $bucket = $this->prefixBucket($fullPath);
            $this->dynamicRoutesByPrefix[$method][$bucket][] = $route;

            // Pre-compile regex pattern for this route
            $this->compiledPatterns[$method][$fullPath] = $this->compilePattern(
                $fullPath,
                $routeData['wheres'] ?? []
            );
        } else {
            // Static route - store for O(1) lookup
            if (!isset($this->staticRoutes[$method])) {
                $this->staticRoutes[$method] = [];
            }
            $this->staticRoutes[$method][$fullPath] = $routeData;
        }
    }

    /**
     * Normalize the many accepted handler forms (closure, "Controller@method"
     * string, [class, method] array, or any callable) into a uniform route
     * data array.
     *
     * @throws InvalidArgumentException When the handler form is not supported.
     */
    protected function parseHandler($handler): array
    {
        if ($handler instanceof Closure) {
            return ['type' => 'closure', 'handler' => $handler];
        }

        /*
         * Offer it to the layers before interpreting it ourselves. A handler
         * the router has no reading for still means something to whoever put
         * it there, and that layer is the only thing that can say what.
         */
        $contributed = $this->routeTypes->parse($handler);

        if ($contributed !== null) {
            return $contributed;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $method] = explode('@', $handler, 2);

            // Use current namespace or fallback to default
            $namespace = $this->currentNamespace ?: $this->namespace;

            return [
                'type' => 'controller',
                'controller' => $namespace . $controller,
                'method' => $method
            ];
        }

        if (is_array($handler) && count($handler) === 2) {
            return [
                'type' => 'controller',
                'controller' => $handler[0],
                'method' => $handler[1]
            ];
        }

        if (is_callable($handler)) {
            return ['type' => 'callable', 'handler' => $handler];
        }

        if (is_string($handler) && class_exists($handler)) {
            // Single-action class: dispatch its handle() (or __invoke()) like a
            // controller, so DI and route-model-binding work unchanged. Bare
            // class-strings otherwise fall through to the error below.
            $actionMethod = method_exists($handler, 'handle') ? 'handle'
                : (method_exists($handler, '__invoke') ? '__invoke' : null);

            if ($actionMethod !== null) {
                return [
                    'type' => 'controller',
                    'controller' => $handler,
                    'method' => $actionMethod,
                ];
            }
        }

        throw new InvalidArgumentException('Invalid route handler provided');
    }

    /**
     * Merge a group's attributes (prefix, middleware, namespace, name) into
     * the current registration state. When a prefix is given without an
     * explicit name, the prefix is also used as the name prefix.
     */
    protected function updateGroupAttributes(array $attributes): void
    {
        if (isset($attributes['prefix'])) {
            $this->currentPrefix = $this->buildPrefix($this->currentPrefix, $attributes['prefix']);
        }

        if (isset($attributes['middleware'])) {
            $middleware = is_string($attributes['middleware'])
                ? [$attributes['middleware']]
                : $attributes['middleware'];
            $this->currentMiddleware = array_merge($this->currentMiddleware, $middleware);
        }

        if (isset($attributes['namespace'])) {
            $this->currentNamespace = $this->buildNamespace($this->currentNamespace, $attributes['namespace']);
        }

        if (isset($attributes['domain'])) {
            $this->currentDomain = (string) $attributes['domain'];
        }

        if (isset($attributes['scopeBindings'])) {
            $this->currentScopeBindings = (bool) $attributes['scopeBindings'];
        }

        if (isset($attributes['name'])) {
            $this->currentName = $this->currentName . $attributes['name'];
        }

        // If prefix is set but no explicit name, use prefix as name prefix
        if (isset($attributes['prefix']) && !isset($attributes['name'])) {
            $prefixAsName = trim($attributes['prefix'], '/') . '.';
            $this->currentName = $this->currentName . $prefixAsName;
        }
    }

    /**
     * Combine the active group prefix with a route path into a normalized,
     * leading-slash absolute path.
     */
    protected function buildFullPath(string $path): string
    {
        $prefix = $this->currentPrefix;
        $path = ltrim($path, '/');

        if ($prefix) {
            return '/' . trim($prefix, '/') . '/' . $path;
        }

        return '/' . $path;
    }

    /**
     * Join an existing prefix with a new segment, trimming slashes so the
     * result has no doubled or trailing separators.
     */
    protected function buildPrefix(string $current, string $new): string
    {
        $current = trim($current, '/');
        $new = trim($new, '/');

        if ($current && $new) {
            return $current . '/' . $new;
        }

        return $current ?: $new;
    }

    /**
     * Join an existing namespace with a new segment, normalizing backslashes
     * and ensuring a single trailing separator.
     */
    protected function buildNamespace(string $current, string $new): string
    {
        $current = rtrim($current, '\\');
        $new = trim($new, '\\');

        if ($current && $new) {
            return $current . '\\' . $new . '\\';
        }

        $namespace = $current ?: $new;
        return $namespace ? rtrim($namespace, '\\') . '\\' : '';
    }

    /**
     * Resolve an incoming request to a matched {@see Route}, or null on miss.
     *
     * Tries the O(1) static lookup first, then falls back to the bucketed
     * dynamic-route scan. Route lifecycle events are fired lazily so they cost
     * nothing when no listener is bound.
     *
     * The match is recorded for {@see current()}, and cleared on entry: the
     * router is a long-lived singleton, so a previous request's match must not
     * answer for one that matches nothing.
     */
    public function findMatchingRoute(Request $request): ?Route
    {
        $method = $request->method();
        $path = $this->normalizePath($request->path());

        $this->currentRoute = null;
        $this->currentRequest = $request;

        // HTTP requires HEAD to be served wherever GET is. Fall back to the GET
        // table for a HEAD request unless the app registered explicit HEAD routes
        // (the response body is irrelevant for HEAD). Without this, HEAD requests
        // — health checks, `curl -I`, some crawlers — 404 on every GET route.
        if ($method === 'HEAD'
            && !isset($this->staticRoutes['HEAD'])
            && !isset($this->dynamicRoutes['HEAD'])) {
            $method = 'GET';
        }

        // Event payloads built lazily — skipped entirely when no listener bound.
        /**
         * Emit point — route.matched
         *
         * Once per request, as matching begins. Named for the moment rather
         * than the outcome: nothing has been found yet, so the route's name
         * and kind are still null — route.dispatching carries those.
         * Payload: {@see RouteEvent}.
         */
        $this->eventLazy(
            RoutingEvents::MATCHED,
            fn (): RouteEvent => new RouteEvent(method: $method, path: $path),
        );

        // Host-constrained routes are more specific than path-only ones, so
        // they are consulted first. The list is empty in apps that never call
        // domain(), which is the common case.
        if (! empty($this->domainRoutes[$method])) {
            $domainMatch = $this->findDomainRoute($method, $path, $request->httpHost());

            if ($domainMatch !== null) {
                return $this->currentRoute = $this->createRoute(
                    $domainMatch['handler'],
                    $domainMatch['parameters']
                );
            }
        }

        // FAST PATH: O(1) static route lookup
        if (isset($this->staticRoutes[$method][$path])) {
            $resolved = $this->createRoute($this->staticRoutes[$method][$path]);

            /**
             * Emit point — route.dispatching
             *
             * A route was found and is about to be handed to the kernel.
             * $strategy says how it was matched — 'static' is the O(1) table
             * hit, which is about matching and not about the route's kind.
             * Payload: {@see RouteEvent}.
             */
            $this->eventLazy(RoutingEvents::DISPATCHING, fn (): RouteEvent => new RouteEvent(
                method: $method,
                path: $path,
                name: $resolved->getName(),
                type: $resolved->getType(),
                strategy: 'static',
            ));

            // Per-request logging is expensive on hot paths; only log when the
            // app is in debug mode.
            if ($this->debugLogging) {
                Logger::info("Static route matched: {$path}", [
                    'method'  => $method,
                    'handler' => $resolved->getType(),
                ]);
            }

            return $this->currentRoute = $resolved;
        }

        // OPTIMIZED PATH: Only check dynamic routes with pre-compiled patterns
        $routeData = $this->findDynamicRoute($method, $path);
        if ($routeData) {
            $resolved = $this->createRoute($routeData['handler'], $routeData['parameters']);

            /**
             * Emit point — route.dispatching
             *
             * As above, for a route matched by compiled pattern rather than
             * from the static table. This is the arm that carries bound URL
             * parameters.
             * Payload: {@see RouteEvent}.
             */
            $this->eventLazy(RoutingEvents::DISPATCHING, fn (): RouteEvent => new RouteEvent(
                method: $method,
                path: $path,
                name: $resolved->getName(),
                type: $resolved->getType(),
                strategy: 'dynamic',
                parameters: $resolved->parameters(),
            ));

            return $this->currentRoute = $resolved;
        }

        return null;
    }

    /**
     * The form of a request path that routes are matched against.
     *
     * A trailing slash is not a different resource: /about/ and /about are the
     * same page, and a link with one used to 404. The root keeps its slash,
     * since trimming it would leave nothing to match.
     */
    protected function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * The verbs that have a route at this path, as {@see ReportsAllowedMethods}
     * describes.
     *
     * Host-constrained routes are left out: whether one applies depends on the
     * host, which a path alone does not carry, and naming a verb that a
     * different host answers would be worse than naming none.
     */
    public function allowedMethods(string $path): array
    {
        $path = $this->normalizePath($path);

        $methods = array_unique(array_merge(
            array_keys($this->staticRoutes),
            array_keys($this->dynamicRoutes),
        ));

        $allowed = [];

        foreach ($methods as $method) {
            if (isset($this->staticRoutes[$method][$path]) || $this->findDynamicRoute($method, $path) !== null) {
                $allowed[] = $method;
            }
        }

        /*
         * HEAD is served wherever GET is, so a client asking what it may do
         * should be told so even though nobody registered a HEAD route.
         */
        if (in_array('GET', $allowed, true) && ! in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * Return the bucket key for prefix-based dynamic-route lookup.
     * Uses the first URL segment when it's static (e.g. "users" for
     * "/users/{id}"); routes whose first segment is itself a placeholder
     * land in the '*' bucket and are matched on every request.
     */
    protected function prefixBucket(string $path): string
    {
        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return '*';
        }
        $first = strstr($trimmed, '/', true);
        if ($first === false) {
            $first = $trimmed;
        }
        return str_contains($first, '{') ? '*' : $first;
    }

    /**
     * Find a parameterized route matching the path using pre-compiled regex.
     *
     * Restricts the candidate set to the path's prefix bucket plus the
     * always-on wildcard bucket, then returns the handler and the extracted
     * parameters (keyed both numerically and by name) for the first match.
     */
    protected function findDynamicRoute(string $method, string $path): ?array
    {
        if (!isset($this->dynamicRoutes[$method])) {
            return null;
        }

        // Restrict the candidate set to routes whose first static segment
        // matches the incoming path, plus the always-on wildcard bucket.
        // Falls back to scanning everything if buckets weren't populated
        // (e.g. routes loaded from a legacy cache file).
        $candidates = null;
        if (isset($this->dynamicRoutesByPrefix[$method])) {
            $bucket = $this->prefixBucket($path);
            $bucketRoutes   = $this->dynamicRoutesByPrefix[$method][$bucket] ?? [];
            $wildcardRoutes = $bucket === '*'
                ? []
                : ($this->dynamicRoutesByPrefix[$method]['*'] ?? []);
            $candidates = $bucket === '*'
                ? $bucketRoutes
                : array_merge($bucketRoutes, $wildcardRoutes);
        }

        foreach (($candidates ?? $this->dynamicRoutes[$method]) as $route) {
            $pattern = $route['pattern'];

            if (!isset($this->compiledPatterns[$method][$pattern])) {
                continue;
            }

            $regex = $this->compiledPatterns[$method][$pattern];

            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match

                // Pass BOTH numeric and named keys. Container::resolveDependencies
                // prefers names but falls back to numeric indices, so:
                //   - handlers whose parameter names match the URL placeholders
                //     get name-based binding (correct even when reordered);
                //   - legacy handlers whose parameter names differ from the
                //     placeholders fall back to positional binding (the prior
                //     behavior) instead of throwing "Cannot resolve parameter".
                $parameters = $matches;
                $paramNames = $route['param_names'] ?? [];
                if ($paramNames && count($paramNames) === count($matches)) {
                    foreach ($paramNames as $i => $name) {
                        $parameters[$name] = $matches[$i];
                    }
                }

                return [
                    'handler' => $route['handler'],
                    'parameters' => $parameters,
                ];
            }
        }

        return null;
    }

    /**
     * Build the {@see Route} value object for a stored route, dispatching on
     * its type — the four the router understands, or one a layer contributed.
     *
     * @throws RuntimeException When the route type is unrecognized.
     */
    protected function createRoute(array $routeData, array $parameters = []): Route
    {
        return $this->buildRoute($routeData, $parameters)
            ->setBindingFields($routeData['binding_fields'] ?? [])
            ->setExcludedMiddleware($routeData['without_middleware'] ?? [])
            ->setBindingBehaviour(
                (bool) ($routeData['scoped'] ?? false),
                (bool) ($routeData['with_trashed'] ?? false),
                $routeData['missing'] ?? null,
                $routeData['param_order'] ?? [],
            );
    }

    /**
     * The {@see Route} for a stored route, before its custom route keys are
     * attached — those are the same for every type, so they are applied once
     * in {@see createRoute()} rather than threaded through five constructors.
     *
     * @throws RuntimeException When the route type is unrecognized.
     */
    protected function buildRoute(array $routeData, array $parameters): Route
    {
        return match ($routeData['type']) {
            'controller' => Route::controller(
                $routeData['controller'],
                $routeData['method'],
                $parameters,
                $routeData['middleware'] ?? [],
                $routeData['name'] ?? null
            ),
            'closure' => Route::closure(
                $routeData['handler'],
                $parameters,
                $routeData['middleware'] ?? [],
                $routeData['name'] ?? null
            ),
            'callable' => Route::callable(
                $routeData['handler'],
                $parameters,
                $routeData['middleware'] ?? [],
                $routeData['name'] ?? null
            ),
            'view' => Route::view(
                $routeData['view'],
                $routeData['data'] ?? [],
                $parameters,
                $routeData['middleware'] ?? [],
                $routeData['name'] ?? null
            ),
            default => $this->createContributedRoute($routeData, $parameters),
        };
    }

    /**
     * Rebuild a route of a kind a feature layer contributed.
     *
     * Reached both on a fresh match and on a route read back from the cache,
     * which is why nothing here asks the type to reconstruct anything: the
     * handler it chose at registration is stored verbatim and handed back.
     *
     * @throws RuntimeException When no layer claims the type — which on a warm
     *         cache means the route outlived the provider that registered it.
     */
    protected function createContributedRoute(array $routeData, array $parameters): Route
    {
        if ($this->routeTypes->get($routeData['type']) === null) {
            throw new RuntimeException("Unknown route type: {$routeData['type']}");
        }

        return Route::ofType(
            $routeData['type'],
            $routeData['handler'],
            $parameters,
            $routeData['middleware'] ?? [],
            $routeData['name'] ?? null
        );
    }

    /**
     * Return every registered route keyed by HTTP method and path.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
