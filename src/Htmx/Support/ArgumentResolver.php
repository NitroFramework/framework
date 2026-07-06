<?php

namespace Nitro\Htmx\Support;

use Nitro\Container\Container;
use Nitro\Http\Request;
use Nitro\Htmx\HtmxComponent;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Resolves action method parameters from the incoming HTTP request.
 *
 * Supports two modes:
 *
 *   1. Named parameters — pulls values by parameter name from GET/POST data.
 *      Works with traditional hx-get/hx-post requests and @hx directive.
 *
 *   2. Positional arguments — reads a JSON-encoded _args array and maps
 *      each value to the method's parameters by position. Works with
 *      hx-action="method(arg1, arg2)" syntax from hx-action.js.
 *
 * Positional mode takes priority when _args is present. Named parameters
 * still work as a fallback and for the traditional @hx directive.
 *
 * Example: if an action is defined as:
 *
 *   public function setTab(int $tab, string $label = 'default')
 *
 * Named:       ?tab=2&label=settings     → setTab(2, 'settings')
 * Positional:  _args=[2,"settings"]      → setTab(2, 'settings')
 * Mixed:       _args=[2] + &label=x      → setTab(2, 'x')
 */
class ArgumentResolver
{
    public function __construct(
        private ?Container $container = null,
    ) {}

    /**
     * Resolve arguments for a component action from the request.
     *
     * @param  HtmxComponent $instance  The component instance
     * @param  string        $action    The action method name
     * @param  Request       $request   The current HTTP request
     * @return array                    Ordered array of resolved arguments
     */
    public function resolve(HtmxComponent $instance, string $action, Request $request): array
    {
        $params = (new ReflectionMethod($instance, $action))->getParameters();

        // Check for positional args from hx-action="method(arg1, arg2)"
        $positionalArgs = $this->extractPositionalArgs($request);

        $args = [];
        $position = 0;

        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType()?->getName();

            // Inject the Request object if the parameter type-hints it
            if ($type === Request::class) {
                $args[] = $request;
                continue;
            }

            // Type-hinted class? Resolve from the container — lets actions
            // declare `public function save(StudentRepo $repo, int $id)`
            // and have $repo injected the same way controller methods do.
            // Skip built-in types ('int', 'string', etc.) — those are
            // request-driven, handled below.
            if ($this->isInjectableClass($param)) {
                $args[] = $this->container?->make($type) ?? null;
                continue;
            }

            // Priority 1: Positional args from _args (hx-action syntax)
            if ($positionalArgs !== null && array_key_exists($position, $positionalArgs)) {
                $args[] = $this->cast($positionalArgs[$position], $type);
                $position++;
                continue;
            }

            // Priority 2: Named parameters from GET/POST data
            $value = $request->get($name) ?? $request->post($name);

            if ($value !== null) {
                $args[] = $this->cast($value, $type);
                $position++;
                continue;
            }

            // Priority 3: Default value or null
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }

            $position++;
        }

        return $args;
    }

    /**
     * True when the parameter type-hints a class we should resolve from
     * the container instead of pulling from the request. Built-in scalar
     * types fall through so request-driven values keep working.
     */
    private function isInjectableClass(\ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }
        // Request is handled explicitly upstream so it works even
        // without a container.
        if ($type->getName() === Request::class) {
            return false;
        }
        return $this->container?->has($type->getName())
            || class_exists($type->getName())
            || interface_exists($type->getName());
    }

    /**
     * Extract positional arguments from the _args request parameter.
     *
     * Returns null if _args is not present (named mode).
     * Returns an array of decoded values if present (positional mode).
     *
     * @param  Request    $request
     * @return array|null
     */
    private function extractPositionalArgs(Request $request): ?array
    {
        $raw = $request->get('_args') ?? $request->post('_args');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        return array_values($decoded);
    }

    /**
     * Cast a value to the declared parameter type.
     *
     * Handles values that are already the correct type (from JSON decode)
     * as well as string values from query/body params.
     *
     * @param  mixed       $value
     * @param  string|null $type
     * @return mixed
     */
    private function cast(mixed $value, ?string $type): mixed
    {
        // null stays null regardless of type
        if ($value === null) {
            return null;
        }

        // If no type declared, return as-is
        if ($type === null) {
            return $value;
        }

        // If value is already the correct type (e.g. from JSON decode), return as-is
        if ($this->isCorrectType($value, $type)) {
            return $value;
        }

        return match ($type) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array'  => (array) $value,
            'string' => (string) $value,
            default  => $value,
        };
    }

    /**
     * Check if a value already matches the declared type.
     *
     * @param  mixed  $value
     * @param  string $type
     * @return bool
     */
    private function isCorrectType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int'    => is_int($value),
            'float'  => is_float($value) || is_int($value),
            'bool'   => is_bool($value),
            'string' => is_string($value),
            'array'  => is_array($value),
            default  => false,
        };
    }
}