<?php

namespace Nitro\Routing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\RouteSignatureParameters;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;
use UnitEnum;

/**
 * Turns registered routes into Nitro's compiled table. All reflection (Symfony route compilation,
 * middleware resolution, controller signatures, implicit bindings) happens here, once.
 *
 * Table shape:
 *   static:  [METHOD => [path => index]]         exact-path routes with no earlier dynamic rival
 *   dynamic: [METHOD => [[regex] | [null, index], ...]]  grouped regex chunks / https-only routes, in order
 *   hosted:  [METHOD => [index, ...]]            domain routes, checked first (as in RouteCollection)
 *   routes:  [index => entry]
 *   names:   [name => index], actions: [controller action => index]
 */
final class RouteCompiler
{
    private const CHUNK = 30;

    /**
     * Route indexes follow $routes->getRoutes(); per-method match order comes from
     * $routes->get($method), which is exactly the list RouteCollection::match() walks
     * (domain routes first; a later duplicate method + uri replaces the earlier one in place).
     */
    public static function compile(RouteCollectionInterface $routes, Router $router, bool $forCache = false): array
    {
        $table = ['static' => [], 'dynamic' => [], 'hosted' => [], 'routes' => [], 'names' => [], 'actions' => []];
        $indexes = [];
        $methods = [];

        foreach (array_values($routes->getRoutes()) as $index => $route) {
            $indexes[spl_object_id($route)] = $index;
            $entry = self::entry($route, $router, $forCache);
            $table['routes'][$index] = $entry;

            if (($name = $route->getName()) !== null && ! isset($table['names'][$name])) {
                $table['names'][$name] = $index;
            }

            if (isset($entry['action']['controller']) && is_string($entry['action']['controller'])) {
                $table['actions'][ltrim($entry['action']['controller'], '\\')] ??= $index;
            }

            foreach ($entry['methods'] as $method) {
                $methods[$method] = true;
            }
        }

        foreach (array_keys($methods) as $method) {
            $normal = [];
            $fallbacks = [];

            foreach ($routes->get($method) as $route) {
                $index = $indexes[spl_object_id($route)];
                $table['routes'][$index]['fallback'] ? $fallbacks[] = $index : $normal[] = $index;
            }

            $earlierDynamic = [];
            $sequence = [];

            foreach ($normal as $index) {
                $entry = $table['routes'][$index];

                if ($entry['hostRegex'] !== null) {
                    $table['hosted'][$method][] = $index; // domain routes precede all others in Laravel
                } elseif ($entry['static'] !== null && ! $entry['https']
                    && ! isset($table['static'][$method][$entry['static']])
                    && ! self::shadowed($entry['static'], $earlierDynamic, $table['routes'])) {
                    $table['static'][$method][$entry['static']] = $index;
                } else {
                    $earlierDynamic[] = $index;
                    $sequence[] = $index;
                }
            }

            /** Fallbacks match last (AbstractRouteCollection::matchAgainstRoutes). */
            foreach ($fallbacks as $index) {
                $table['routes'][$index]['hostRegex'] !== null
                    ? $table['hosted'][$method][] = $index
                    : $sequence[] = $index;
            }

            $table['dynamic'][$method] = self::chunks($sequence, $table['routes']);
        }

        return $table;
    }

    /**
     * Consecutive plain routes share one grouped regex; https-only routes are matched on their
     * own (they need a scheme check) without changing the order.
     */
    private static function chunks(array $sequence, array $routes): array
    {
        $chunks = [];
        $pending = [];

        foreach ($sequence as $index) {
            if ($routes[$index]['https']) {
                if ($pending !== []) {
                    $chunks[] = self::groupRegex($pending, $routes);
                    $pending = [];
                }

                $chunks[] = [null, $index];

                continue;
            }

            $pending[] = $index;

            if (count($pending) === self::CHUNK) {
                $chunks[] = self::groupRegex($pending, $routes);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $chunks[] = self::groupRegex($pending, $routes);
        }

        return $chunks;
    }
    /**
     * PHP source for bootstrap/cache/routes.php.
     */
    public static function export(array $table): string
    {
        return '<?php'.PHP_EOL.PHP_EOL.'// Generated by `php artisan route:cache` (Nitro). Do not edit.'.PHP_EOL.PHP_EOL
            .'return \Nitro\Routing\CompiledRoutes::cached('.var_export($table, true).');'.PHP_EOL;
    }

    /**
     * The route's URI and parameter names, for UrlGenerator::route() to fill in; null when the
     * route needs RouteUrlGenerator (a domain, optional parameters, binding fields, a scheme of
     * its own, or placeholders it would not read as plain named parameters).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private static function urlTemplate(BaseRoute $route): ?array
    {
        if ($route->getDomain() !== null || $route->httpOnly() || $route->httpsOnly()
            || $route->bindingFields() !== [] || $route->getOptionalParameterNames() !== []) {
            return null;
        }

        $names = $route->parameterNames();
        preg_match_all('/\{(.*?)\}/', $route->uri(), $placeholders);

        if ($placeholders[1] !== $names || count(array_unique($names)) !== count($names)) {
            return null;
        }

        foreach ($names as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                return null;
            }
        }

        return [$route->uri(), $names];
    }

    private static function entry(BaseRoute $route, Router $router, bool $forCache): array
    {
        $symfony = $route->toSymfonyRoute()->compile();
        $regex = $symfony->getRegex();
        $hostRegex = $symfony->getHostRegex();
        $action = $route->getAction();

        $callable = self::callable($action);
        $reflector = self::reflector($callable);

        $middleware = [];

        foreach ($router->gatherRouteMiddleware($route) as $item) {
            $middleware[] = self::parseMiddleware($item, $forCache);
        }

        if ($forCache) {
            $action = self::serializable($action);
        }

        $controller = null;

        if (is_string($callable)) {
            [$class, $method] = Str::parseCallback($callable);
            $controller = [ltrim($class, '\\'), $method ?? '__invoke'];
        }

        $static = null;

        if ($symfony->getPathVariables() === [] && $hostRegex === null) {
            $static = '/'.trim($symfony->getStaticPrefix(), '/');
        }

        return [
            'methods' => $route->methods(),
            'uri' => $route->uri(),
            'action' => $action,
            'fallback' => $route->isFallback,
            'defaults' => $route->defaults,
            'wheres' => $route->wheres,
            'bindingFields' => $route->bindingFields(),
            'lockSeconds' => $route->locksFor(),
            'waitSeconds' => $route->waitsFor(),
            'withTrashed' => $route->allowsTrashedBindings(),
            'regex' => $regex,
            'hostRegex' => $hostRegex,
            'https' => $route->httpsOnly(),
            'static' => $static,
            'params' => $symfony->getPathVariables(),
            'names' => $route->parameterNames(),
            'url' => self::urlTemplate($route),
            'middleware' => $middleware,
            'controller' => $controller,
            'plan' => $reflector ? self::plan($reflector) : null,
            'bindings' => self::implicitBindings($action),
        ];
    }

    /**
     * An earlier dynamic route matching this exact path takes precedence in Laravel,
     * so the static route must stay in ordered (regex) matching.
     */
    private static function shadowed(string $path, array $earlierDynamic, array $routes): bool
    {
        foreach ($earlierDynamic as $index) {
            if (! $routes[$index]['fallback'] && preg_match($routes[$index]['regex'], $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combine route regexes into one alternation using (*MARK) to identify the matched route.
     * Named groups are renamed to g{index}x{n} so names never collide across routes.
     */
    private static function groupRegex(array $indexes, array $routes): array
    {
        $parts = [];

        foreach ($indexes as $index) {
            $regex = $routes[$index]['regex'];
            $inner = substr($regex, 2, strrpos($regex, '$') - 2); // strip "{^" ... "$}flags"
            $n = 0;
            $inner = preg_replace_callback('/\(\?P<([A-Za-z_][A-Za-z0-9_]*)>/', function () use ($index, &$n) {
                return '(?P<g'.$index.'x'.($n++).'>';
            }, $inner);

            $parts[] = $inner.'(*MARK:'.$index.')';
        }

        return ['{^(?:'.implode('|', $parts).')$}sDu'];
    }

    private static function parseMiddleware(mixed $item, bool $forCache): array
    {
        if ($item instanceof Closure) {
            return [$forCache ? serialize(SerializableClosure::unsigned($item)) : $item, [], true];
        }

        [$name, $parameters] = array_pad(explode(':', $item, 2), 2, null);

        return [$name, $parameters === null ? [] : explode(',', $parameters), false];
    }

    private static function callable(array $action): mixed
    {
        $uses = $action['uses'] ?? null;

        if (RouteAction::containsSerializedClosure($action)) {
            return unserialize($uses)->getClosure();
        }

        return $uses;
    }

    private static function reflector(mixed $callable): ?ReflectionFunctionAbstract
    {
        try {
            if ($callable instanceof Closure) {
                return new ReflectionFunction($callable);
            }

            if (is_string($callable)) {
                [$class, $method] = Str::parseCallback($callable);

                return method_exists($class, $method ?? '__invoke') ? new ReflectionMethod($class, $method ?? '__invoke') : null;
            }
        } catch (ReflectionException) {
        }

        return null;
    }

    /**
     * Argument plan mirroring ResolvesRouteDependencies::resolveMethodDependencies():
     * one [class|null, hasDefault, default, isEnum] per parameter. Null = not compilable,
     * dispatch falls back to Laravel's reflective dispatchers.
     */
    private static function plan(ReflectionFunctionAbstract $reflector): ?array
    {
        $plan = [];

        foreach ($reflector->getParameters() as $parameter) {
            if ($parameter->getAttributes() !== [] || $parameter->isVariadic()) {
                return null;
            }

            $class = Reflector::getParameterClassName($parameter);
            $hasDefault = $parameter->isDefaultValueAvailable();
            $default = $hasDefault ? $parameter->getDefaultValue() : null;

            if ($hasDefault && ! self::exportable($default)) {
                return null;
            }

            $plan[] = [$class, $hasDefault, $default, $class !== null && enum_exists($class)];
        }

        return $plan;
    }

    private static function exportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function implicitBindings(array $action): array
    {
        $bindings = ['enums' => [], 'models' => []];

        try {
            foreach (RouteSignatureParameters::fromAction($action, ['backedEnum' => true]) as $parameter) {
                $bindings['enums'][] = [$parameter->getName(), $parameter->getType()?->getName()];
            }

            foreach (RouteSignatureParameters::fromAction($action, ['subClass' => UrlRoutable::class]) as $parameter) {
                $bindings['models'][] = [$parameter->getName(), Reflector::getParameterClassName($parameter)];
            }
        } catch (Throwable) {
            /** Unresolvable action (e.g. missing controller): Laravel fails at dispatch, so do we. */
        }

        return $bindings;
    }

    private static function serializable(array $action): array
    {
        if (($action['uses'] ?? null) instanceof Closure) {
            $action['uses'] = serialize(SerializableClosure::unsigned($action['uses']));
        }

        if (($action['missing'] ?? null) instanceof Closure) {
            $action['missing'] = serialize(SerializableClosure::unsigned($action['missing']));
        }

        return $action;
    }
}
