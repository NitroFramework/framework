<?php

namespace Nitro\Routing;

use ArrayIterator;
use Countable;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Arr;
use IteratorAggregate;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Traversable;

/**
 * Route collection backed by the compiled table. Route objects are built only when something
 * asks for one (the matched route, a URL by name, Route::getRoutes()), and each prototype is
 * built at most once per process (matched routes are clones carrying their own parameters).
 *
 * Routes added after compilation (e.g. by a package's boot() while routes are cached) live in
 * a regular RouteCollection and are compiled on first match, like CompiledRouteCollection does.
 */
class CompiledRoutes implements RouteCollectionInterface, IteratorAggregate, Countable
{
    /** @var array<int, Route> */
    protected array $prototypes = [];

    protected ?RouteCollection $extra = null;

    protected ?CompiledRoutes $extraCompiled = null;

    final public function __construct(protected array $table, protected Router $router)
    {
    }

    /** Set while load() reads a cache file, so cached() only returns the table. */
    private static bool $loading = false;

    public static function fromFile(string $path, Router $router): static
    {
        return new static(static::load($path), $router);
    }

    /**
     * Read a route cache file without installing it.
     */
    public static function load(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        static::$loading = true;

        try {
            $table = require $path;
        } finally {
            static::$loading = false;
        }

        return is_array($table) ? $table : null;
    }

    /**
     * Entry point of the route cache file. Laravel's RouteServiceProvider `require`s the cache
     * file after boot (as it does with its own format); that installs the table on the router
     * unless the router already loaded it.
     */
    public static function cached(array $table): array
    {
        if (! static::$loading) {
            $router = Container::getInstance()->make('router');

            if ($router instanceof Router && ! $router->getRoutes() instanceof self) {
                $router->useCompiledRoutes(new static($table, $router));
            }
        }

        return $table;
    }

    public static function fromCollection(RouteCollectionInterface $routes, Router $router): static
    {
        $compiled = new static(RouteCompiler::compile($routes, $router), $router);

        /** In-memory compile: reuse the registered Route objects as prototypes. */
        foreach (array_values($routes->getRoutes()) as $index => $route) {
            if ($route instanceof Route) {
                $route->nitroIndex = $index;
                $compiled->prototypes[$index] = $route;
            }
        }

        return $compiled;
    }

    public function table(): array
    {
        return $this->table;
    }

    public function entry(int $index): array
    {
        return $this->table['routes'][$index];
    }

    public function implicitBindings(int $index): ?array
    {
        return $this->table['routes'][$index]['bindings'] ?? null;
    }

    /**
     * Find the route for a request, or throw 404 / 405.
     *
     * @return array{0: Route, 1: array|null} The matched route and its compiled entry
     *                                        (null for synthesized OPTIONS responses).
     */
    public function find(Request $request): array
    {
        $method = $request->getMethod();
        $path = rawurldecode(rtrim($request->getPathInfo(), '/')) ?: '/';

        $match = $this->matchMethod($method, $path, $request);

        /**
         * Like CompiledRouteCollection: runtime-added routes are tried when the compiled table
         * has no match, and win over a compiled fallback route.
         */
        if ($this->extra !== null && ($match === null || $this->table['routes'][$match[0]]['fallback'])
            && ($extra = $this->extraCompiled()->matchMethod($method, $path, $request)) !== null
            && ($match === null || ! $this->extraCompiled()->entry($extra[0])['fallback'])) {
            $route = $this->extraCompiled()->route($extra[0], $extra[1]);
            $route->nitroIndex = null; // indexes refer to the extra table, not this one

            return [$route, $this->extraCompiled()->entry($extra[0])];
        }

        if ($match !== null) {
            return [$this->route($match[0], $match[1]), $this->table['routes'][$match[0]]];
        }

        $others = [];

        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $other) {
            if ($other !== $method && $this->matchMethod($other, $path, $request) !== null) {
                $others[] = $other;
            }
        }

        if ($others === []) {
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        if ($method === 'OPTIONS') {
            return [(new Route('OPTIONS', $request->path(), fn () => new Response('', 200, ['Allow' => implode(',', $others)])))
                ->setRouter($this->router)->setContainer(Container::getInstance())
                ->setMatchedParameters([]), null];
        }

        throw new MethodNotAllowedHttpException($others, sprintf(
            'The %s method is not supported for route %s. Supported methods: %s.',
            $method, $request->path(), implode(', ', $others)
        ));
    }

    /**
     * @return array{0: int, 1: array}|null
     */
    public function matchMethod(string $method, string $path, Request $request): ?array
    {
        $t = $this->table;

        foreach ($t['hosted'][$method] ?? [] as $index) {
            if (($parameters = $this->matchHosted($t['routes'][$index], $path, $request)) !== null) {
                return [$index, $parameters];
            }
        }

        if (isset($t['static'][$method][$path])) {
            $index = $t['static'][$method][$path];

            return [$index, $this->replaceDefaults([], $t['routes'][$index]['defaults'])];
        }

        foreach ($t['dynamic'][$method] ?? [] as $chunk) {
            if ($chunk[0] === null) {
                if (($parameters = $this->matchHosted($t['routes'][$chunk[1]], $path, $request)) !== null) {
                    return [$chunk[1], $parameters];
                }

                continue;
            }

            if (preg_match($chunk[0], $path, $m)) {
                $index = (int) $m['MARK'];
                $parameters = [];

                foreach ($t['routes'][$index]['params'] as $n => $name) {
                    $value = $m['g'.$index.'x'.$n] ?? null;

                    if (is_string($value) && $value !== '') {
                        $parameters[$name] = $value;
                    }
                }

                return [$index, $this->replaceDefaults($parameters, $t['routes'][$index]['defaults'])];
            }
        }

        return null;
    }

    protected function matchHosted(array $entry, string $path, Request $request): ?array
    {
        if ($entry['https'] && ! $request->secure()) {
            return null;
        }

        if (! preg_match($entry['regex'], $path, $pathMatches)) {
            return null;
        }

        $host = [];

        if ($entry['hostRegex'] !== null) {
            if (! preg_match($entry['hostRegex'], $request->getHost(), $hostMatches)) {
                return null;
            }

            $host = $this->toKeys(array_slice($hostMatches, 1), $entry['names']);
        }

        return $this->replaceDefaults(
            array_merge($host, $this->toKeys(array_slice($pathMatches, 1), $entry['names'])),
            $entry['defaults']
        );
    }

    /** RouteParameterBinder::matchToKeys() */
    protected function toKeys(array $matches, array $names): array
    {
        return array_filter(
            array_intersect_key($matches, array_flip($names)),
            fn ($value) => is_string($value) && $value !== ''
        );
    }

    /** RouteParameterBinder::replaceDefaults() */
    protected function replaceDefaults(array $parameters, array $defaults): array
    {
        if ($defaults === []) {
            return $parameters;
        }

        foreach ($parameters as $key => $value) {
            $parameters[$key] = $value ?? Arr::get($defaults, $key);
        }

        foreach ($defaults as $key => $value) {
            if (! isset($parameters[$key])) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The matched route: a clone of the prototype carrying this request's parameters.
     */
    public function route(int $index, array $parameters): Route
    {
        return (clone $this->prototype($index))->setMatchedParameters($parameters);
    }

    public function prototype(int $index): Route
    {
        if (isset($this->prototypes[$index])) {
            return $this->prototypes[$index];
        }

        $a = $this->table['routes'][$index];

        if (empty($a['action']['prefix'] ?? '')) {
            $uri = $a['uri'];
        } else {
            /** Route::prefix() is re-applied by the constructor; strip it (CompiledRouteCollection::newRoute). */
            $prefix = trim($a['action']['prefix'], '/');
            $uri = trim(implode('/', array_slice(
                explode('/', trim($a['uri'], '/')),
                count($prefix !== '' ? explode('/', $prefix) : [])
            )), '/');
        }

        $route = $this->router->newRoute($a['methods'], $uri === '' ? '/' : $uri, $a['action'])
            ->setFallback($a['fallback'])
            ->setDefaults($a['defaults'])
            ->setWheres($a['wheres'])
            ->setBindingFields($a['bindingFields'])
            ->block($a['lockSeconds'] ?? null, $a['waitSeconds'] ?? null)
            ->withTrashed($a['withTrashed'] ?? false);

        $route->nitroIndex = $index;

        return $this->prototypes[$index] = $route;
    }

    protected function extraCompiled(): CompiledRoutes
    {
        return $this->extraCompiled ??= static::fromCollection($this->extra, $this->router);
    }


    public function add(BaseRoute $route)
    {
        ($this->extra ??= new RouteCollection)->add($route);
        $this->extraCompiled = null;

        return $route;
    }

    public function refreshNameLookups()
    {
        $this->extra?->refreshNameLookups();
    }

    public function refreshActionLookups()
    {
        $this->extra?->refreshActionLookups();
    }

    public function match(Request $request)
    {
        return $this->find($request)[0];
    }

    public function get($method = null)
    {
        if ($method === null) {
            return $this->getRoutes();
        }

        return array_values(array_filter($this->getRoutes(), fn ($route) => in_array($method, $route->methods(), true)));
    }

    public function hasNamedRoute($name)
    {
        return isset($this->table['names'][$name]) || ($this->extra?->hasNamedRoute($name) ?? false);
    }

    public function getByName($name)
    {
        if (isset($this->table['names'][$name])) {
            return $this->prototype($this->table['names'][$name]);
        }

        return $this->extra?->getByName($name);
    }

    public function getByAction($action)
    {
        $action = ltrim($action, '\\');

        if (isset($this->table['actions'][$action])) {
            return $this->prototype($this->table['actions'][$action]);
        }

        return $this->extra?->getByAction($action);
    }

    public function getRoutes()
    {
        $routes = [];

        foreach (array_keys($this->table['routes']) as $index) {
            $routes[] = $this->prototype($index);
        }

        return array_merge($routes, $this->extra?->getRoutes() ?? []);
    }

    public function getRoutesByMethod()
    {
        $byMethod = [];

        foreach ($this->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $byMethod[$method][$route->getDomain().$route->uri()] = $route;
            }
        }

        return $byMethod;
    }

    public function getRoutesByName()
    {
        $byName = [];

        foreach ($this->getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $byName[$name] ??= $route;
            }
        }

        return $byName;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getRoutes());
    }

    public function count(): int
    {
        return count($this->table['routes']) + ($this->extra?->count() ?? 0);
    }
}
