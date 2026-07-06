<?php

namespace Nitro\Support;

use BadMethodCallException;
use Closure;

/**
 * Lets a class be extended at runtime with "macro" methods registered from
 * outside, without subclassing or editing the class.
 *
 * Feature layers (e.g. the HTMX layer) use this to bolt domain-specific
 * helpers onto core classes like the router, keeping the core free of any
 * dependency on those layers. Closures are bound to the instance and class
 * scope, so a macro can call the host object's protected members.
 */
trait Macroable
{
    /** @var array<string, callable> Registered macros keyed by method name. */
    protected static array $macros = [];

    /**
     * Register a macro under the given method name. Once registered, calling
     * $instance->{$name}(...) invokes the macro.
     */
    public static function macro(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Determine whether a macro with the given name has been registered.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Dispatch a call to a registered macro, binding closures to the current
     * instance and class scope so they may access protected members.
     *
     * @throws BadMethodCallException When no macro matches the method name.
     */
    public function __call(string $method, array $parameters)
    {
        if (!static::hasMacro($method)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', static::class, $method)
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            // A static closure (`static fn () => …`) can't be bound — bindTo()
            // returns null and warns. Only adopt the bound copy when binding
            // succeeded; otherwise invoke the macro unbound instead of fataling
            // on a null call.
            $bound = @$macro->bindTo($this, static::class);
            if ($bound instanceof Closure) {
                $macro = $bound;
            }
        }

        return $macro(...$parameters);
    }
}
