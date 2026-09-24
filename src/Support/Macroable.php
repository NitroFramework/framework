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
     * Register every public method of an object as a macro.
     *
     * For a package shipping a group of related helpers: one object declares
     * them as ordinary methods, with signatures and docblocks a reader and a
     * static analyser can both follow, rather than a pile of loose closures.
     *
     * @param object|class-string $mixin
     * @param bool $replace Whether a name already registered is overwritten.
     */
    public static function mixin(object|string $mixin, bool $replace = true): void
    {
        $methods = (new \ReflectionClass($mixin))->getMethods(
            \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED
        );

        // Each method returns the closure to register, so a static one needs
        // no object and an instance one is invoked on the mixin itself.
        $instance = is_string($mixin) ? null : $mixin;

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            if (! $replace && static::hasMacro($method->getName())) {
                continue;
            }

            if (! $method->isStatic() && $instance === null) {
                $instance = new $mixin();
            }

            static::macro(
                $method->getName(),
                $method->invoke($method->isStatic() ? null : $instance)
            );
        }
    }

    /**
     * Determine whether a macro with the given name has been registered.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Forget every registered macro.
     *
     * A macro registered in a test outlives the test, because the store is
     * static. This is how a test puts the class back as it found it.
     */
    public static function flushMacros(): void
    {
        static::$macros = [];
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

    /**
     * Dispatch a static call to a registered macro.
     *
     * A class whose helpers are all static — Str, say — is never an instance,
     * so {@see __call()} is never reached and a macro on it was unreachable.
     *
     * @throws BadMethodCallException When no macro matches the method name.
     */
    public static function __callStatic(string $method, array $parameters)
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', static::class, $method)
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo(null, static::class);
        }

        return $macro(...$parameters);
    }
}
