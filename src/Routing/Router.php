<?php

namespace Nitro\Routing;

use Closure;
use InvalidArgumentException;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\CoreEvents;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Concerns\CompilesRoutePatterns;
use Nitro\Routing\Concerns\GeneratesUrls;
use Nitro\Routing\Concerns\ManagesRouteCache;
use Nitro\Support\Logger;
use Nitro\Support\Macroable;
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
 * The router is {@see Macroable}: feature layers register extra registration
 * helpers (e.g. the HTMX layer's `htmx()` page route) from their service
 * provider, so the core router depends on no feature layer.
 */
class Router implements RouterInterface
{
    use CompilesRoutePatterns,
        ManagesRouteCache,
        GeneratesUrls,
        DispatchesEvents,
        Macroable;

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
        protected ConfigRepository $config
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
        $param = $this->singularize($name);
        $wild  = $base . '/{' . $param . '}';
        $named = str_replace('/', '.', $name);

        $actions = [
            'index'   => ['GET',    $base],
            'create'  => ['GET',    $base . '/create'],
            'store'   => ['POST',   $base],
            'show'    => ['GET',    $wild],
            'edit'    => ['GET',    $wild . '/edit'],
            'update'  => ['PUT',    $wild],
            'destroy' => ['DELETE', $wild],
        ];

        if (!empty($options['only'])) {
            $actions = array_intersect_key($actions, array_flip((array) $options['only']));
        }
        if (!empty($options['except'])) {
            $actions = array_diff_key($actions, array_flip((array) $options['except']));
        }

        foreach ($actions as $action => [$method, $path]) {
            $this->addRoute($method, $path, [$controller, $action])->name("{$named}.{$action}");
        }

        return $this;
    }

    /**
     * Naive singularisation for resource route parameters (photos → photo).
     * Mirrors the trailing-"s" heuristic used elsewhere (e.g. the unique rule);
     * good enough for conventional resource names.
     */
    protected function singularize(string $name): string
    {
        $segment = str_contains($name, '/') ? substr(strrchr($name, '/'), 1) : $name;
        return rtrim($segment, 's') ?: $segment;
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
     * Append middleware to the stack applied to subsequently registered
     * routes. Accepts a single name or an array of names.
     */
    public function middleware($middleware): static
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        $this->currentMiddleware = array_merge($this->currentMiddleware, $middleware);
        return $this;
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

        $this->storeRoute($method, $fullPath, $routeData);

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
            $this->compiledPatterns[$method][$fullPath] = $this->compilePattern($fullPath);
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
     */
    public function findMatchingRoute(Request $request): ?Route
    {
        $method = $request->method();
        $path = $request->path();

        // Event payloads built lazily — skipped entirely when no listener bound.
        $this->eventLazy(CoreEvents::ROUTE_MATCHED, fn() => [
            'method' => $method,
            'path'   => $path,
        ]);

        // FAST PATH: O(1) static route lookup
        if (isset($this->staticRoutes[$method][$path])) {
            $resolved = $this->createRoute($this->staticRoutes[$method][$path]);

            $this->eventLazy(CoreEvents::ROUTE_DISPATCHING, fn() => [
                'type'    => 'static',
                'route'   => $path,
                'handler' => $resolved->getType(),
            ]);

            // Per-request logging is expensive on hot paths; only log when the
            // app is in debug mode.
            if ($this->debugLogging) {
                Logger::info("Static route matched: {$path}", [
                    'method'  => $method,
                    'handler' => $resolved->getType(),
                ]);
            }

            return $resolved;
        }

        // OPTIMIZED PATH: Only check dynamic routes with pre-compiled patterns
        $routeData = $this->findDynamicRoute($method, $path);
        if ($routeData) {
            $resolved = $this->createRoute($routeData['handler'], $routeData['parameters']);

            $this->eventLazy(CoreEvents::ROUTE_DISPATCHING, fn() => [
                'type'       => 'dynamic',
                'parameters' => $routeData['parameters'],
                'handler'    => $resolved->getType(),
            ]);

            return $resolved;
        }

        return null;
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
     * its type (controller, closure, callable or view).
     *
     * @throws RuntimeException When the route type is unrecognized.
     */
    protected function createRoute(array $routeData, array $parameters = []): Route
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
            default => throw new RuntimeException("Unknown route type: {$routeData['type']}")
        };
    }

    /**
     * Return every registered route keyed by HTTP method and path.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
