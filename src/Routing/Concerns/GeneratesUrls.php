<?php

namespace Nitro\Routing\Concerns;

use InvalidArgumentException;
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

        $method = $this->lastRoute['method'];
        $path = $this->lastRoute['path'];

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
     * @throws InvalidArgumentException When the route is unknown or required
     *         parameters are missing.
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->getRouteByName($name);

        if (!$route) {
            throw new InvalidArgumentException("Route [{$name}] not found");
        }

        $path = $route['path'];

        // Positional parameters: route('courses.show', ['food-hygiene']) fills
        // the placeholders left to right, so a single-parameter route does not
        // have to name its parameter.
        if ($parameters !== [] && array_is_list($parameters)) {
            preg_match_all('/\{([^}]+)\}/', $path, $placeholders);

            $named = [];
            foreach ($placeholders[1] as $index => $placeholder) {
                if (array_key_exists($index, $parameters)) {
                    $named[rtrim($placeholder, '?')] = $parameters[$index];
                }
            }

            // Anything beyond the placeholders is not positional; keep it so it
            // can become a query string below.
            $parameters = $named + array_slice($parameters, count($named));
        }

        $query = [];

        foreach ($parameters as $key => $value) {
            $placeholder = '{' . $key . '}';

            if (! str_contains($path, $placeholder)) {
                // Not a path parameter, so it belongs in the query string. This
                // is what makes route('courses.index', ['categories' => [...]])
                // produce ?categories[]=food-safety; previously it was dropped,
                // and an array value fatally stringified to "Array".
                $query[$key] = $value instanceof \BackedEnum ? $value->value : $value;

                continue;
            }

            $path = str_replace($placeholder, rawurlencode($this->routeParameterValue($value)), $path);
        }

        // Check for unreplaced parameters
        if (preg_match('/\{[^}]+\}/', $path)) {
            throw new InvalidArgumentException("Missing parameters for route [{$name}]");
        }

        return $query === [] ? $path : $path . '?' . http_build_query($query);
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
