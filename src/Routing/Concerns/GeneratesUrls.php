<?php

namespace Nitro\Routing\Concerns;

use InvalidArgumentException;
use Nitro\Routing\Exceptions\RouteNotFoundException;
use Nitro\Routing\Exceptions\UrlGenerationException;
use RuntimeException;

/**
 * Named-route registration and URL generation for the
 * {@see \Nitro\Routing\Router}.
 *
 * Lets a freshly defined route be given a name, keeps a registry of named
 * routes, and builds concrete URLs by substituting parameters back into a
 * named route's path.
 */
trait GeneratesUrls
{
    /** Named routes registry */
    protected array $namedRoutes = [];

    /** Last registered route reference for chaining */
    protected ?array $lastRoute = null;

    /**
     * Every route the last registration call produced, for chaining onto.
     *
     * One entry for a single verb, one per verb for match() and any(), so what
     * follows the call reaches all of them.
     *
     * @var array<int, array{method: string, path: string}>
     */
    protected array $lastRoutes = [];

    /**
     * Assign a name to the most recently registered route (prefixed by the
     * current group name) and record it in the named-route registry.
     *
     * @throws RuntimeException When called before any route has been defined.
     */
    public function name(string $name): static
    {
        if (!$this->lastRoute) {
            throw new RuntimeException('No route to name. Call name() immediately after defining a route.');
        }

        // The first of them, so a name registered for several verbs at once
        // reports the verb it was written with rather than the last one stored.
        $first = $this->lastRoutes[0] ?? $this->lastRoute;

        $method = $first['method'];
        $path = $first['path'];

        // Build full name with current group prefix
        $fullName = $this->currentName . $name;

        // Written into every storage location the route lives in — see
        // setOnLastRoute(), which explains why there are three of them.
        $this->setOnLastRoute('name', $fullName);

        // Store in named routes registry
        $this->namedRoutes[$fullName] = [
            'method' => $method,
            'path' => $path,
        ];

        return $this;
    }

    /**
     * Look up a named route's stored method/path, or null if unknown.
     */
    public function getRouteByName(string $name): ?array
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route by substituting the given parameters
     * into its path.
     *
     * A placeholder still standing at the end was never given a value, and is
     * named in the exception: "missing parameters" on a route with four of them
     * says nothing about which one to pass.
     *
     * @throws RouteNotFoundException  When no route carries that name.
     * @throws UrlGenerationException  When the route needs a parameter nothing gave it.
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->getRouteByName($name);

        if (!$route) {
            throw RouteNotFoundException::forName($name);
        }

        $path = $route['path'];

        /*
         * Placeholder text by parameter name, so "{post:slug}" is reachable as
         * 'post' — the same name the route binds it under. Looking for a
         * literal "{post}" would miss it and quietly append ?post=… instead.
         */
        $placeholders = [];

        foreach ($this->rawParameters($path) as $raw) {
            $placeholders[$this->parseParameter($raw)['name']] = '{' . $raw . '}';
        }

        // Positional parameters: route('courses.show', ['food-hygiene']) fills
        // the placeholders left to right, so a single-parameter route does not
        // have to name its parameter.
        if ($parameters !== [] && array_is_list($parameters)) {
            $names = array_keys($placeholders);

            $named = [];
            foreach ($names as $index => $name) {
                if (array_key_exists($index, $parameters)) {
                    $named[$name] = $parameters[$index];
                }
            }

            // Anything beyond the placeholders is not positional; keep it so it
            // can become a query string below.
            $parameters = $named + array_slice($parameters, count($named));
        }

        $query = [];

        foreach ($parameters as $key => $value) {
            $placeholder = $placeholders[$key] ?? null;

            if ($placeholder === null) {
                // Not a path parameter, so it belongs in the query string. This
                // is what makes route('courses.index', ['categories' => [...]])
                // produce ?categories[]=food-safety; previously it was dropped,
                // and an array value fatally stringified to "Array".
                $query[$key] = $value instanceof \BackedEnum ? $value->value : $value;

                continue;
            }

            $path = str_replace($placeholder, $this->encodeParameter($this->routeParameterValue($value)), $path);
        }

        /*
         * An optional placeholder nobody filled simply goes away, with the
         * slash that introduced it: "/posts/{page?}" is "/posts" when no page
         * is given. It used to survive to the check below and be reported as
         * a missing parameter, which is the one thing optional cannot mean.
         */
        $path = preg_replace('#/?\{[^}]+\?\}#', '', $path) ?? $path;

        if ($path === '') {
            $path = '/';
        }

        if (preg_match_all('/\{([^}]+)\}/', $path, $unfilled) === 1 || $unfilled[1] !== []) {
            throw UrlGenerationException::forMissingParameters(
                $name,
                array_map(static fn (string $p): string => rtrim($p, '?'), $unfilled[1]),
            );
        }

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    /**
     * Characters that survive encoding in a path segment.
     *
     * A URL is escaped so a value cannot change the shape of the path, but a
     * reserved character written deliberately has to arrive as written. The
     * important one is the slash: a {path} constrained with where('path', '.*')
     * holds a path, and encoding its separators produces a URL that no longer
     * matches the route that generated it.
     *
     * @var array<string, string>
     */
    private const KEEP_ENCODED = [
        '%2F' => '/',
        '%40' => '@',
        '%3A' => ':',
        '%3B' => ';',
        '%2C' => ',',
        '%3D' => '=',
        '%2B' => '+',
        '%21' => '!',
        '%2A' => '*',
        '%7C' => '|',
        '%3F' => '?',
        '%26' => '&',
        '%23' => '#',
        '%25' => '%',
    ];

    /** Escape a value for a path segment, leaving the reserved set readable. */
    private function encodeParameter(string $value): string
    {
        return strtr(rawurlencode($value), self::KEEP_ENCODED);
    }

    /**
     * A path parameter's string form.
     *
     * A model is asked for its route key, so route('courses.show', $course)
     * works — which is what anybody writes first.
     */
    private function routeParameterValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getRouteKey')) {
                return (string) $value->getRouteKey();
            }

            if (method_exists($value, 'getKey')) {
                return (string) $value->getKey();
            }
        }

        return (string) $value;
    }

    /**
     * Return the full named-route registry keyed by route name.
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }
}
