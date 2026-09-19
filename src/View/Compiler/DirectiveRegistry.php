<?php

namespace Nitro\View\Compiler;

/**
 * Where custom directives and precompilers are recorded before anything compiles.
 *
 * Declaring a directive is a statement about the template language, not a request
 * to build the compiler. Holding the registry on {@see BladeCompiler} made it one:
 * that class flattens sixteen Compiles* traits, and a static call is enough to load
 * all of them, so every provider that registered a directive in boot() pulled the
 * whole compiler into requests that render nothing — a JSON endpoint paid for the
 * view layer because something, somewhere, had declared an `@hx`.
 *
 * This class carries no dependencies of its own, so registration costs a single
 * small autoload and the compiler reads what was recorded when it is genuinely
 * built.
 */
final class DirectiveRegistry
{
    /**
     * Directives registered at runtime, by name.
     *
     * @var array<string, callable>
     */
    private static array $directives = [];

    /**
     * Callbacks that transform raw template source before any compilation pass.
     *
     * @var array<int, callable>
     */
    private static array $precompilers = [];

    /**
     * Record a directive compiled by a callback.
     *
     * @param string   $name     Directive name without the @ (e.g. 'money' for @money).
     * @param callable $callback Callable(string $arguments): string
     */
    public static function directive(string $name, callable $callback): void
    {
        self::$directives[$name] = $callback;
    }

    /**
     * Record a precompiler — a callback that rewrites raw template source before
     * any compilation pass, used to expand custom tags into directives the
     * compiler already understands.
     *
     * @param callable $callback Callable(string $template): string
     */
    public static function precompiler(callable $callback): void
    {
        self::$precompilers[] = $callback;
    }

    /**
     * The callback registered for a directive name, or null if there is none.
     *
     * Returns the entry rather than the whole map because this runs once per
     * unrecognised directive in every template compiled.
     */
    public static function find(string $name): ?callable
    {
        return self::$directives[$name] ?? null;
    }

    /**
     * Every registered directive, by name.
     *
     * @return array<string, callable>
     */
    public static function directives(): array
    {
        return self::$directives;
    }

    /**
     * Every registered precompiler, in registration order.
     *
     * @return array<int, callable>
     */
    public static function precompilers(): array
    {
        return self::$precompilers;
    }

    /** Forget every registered directive. */
    public static function flushDirectives(): void
    {
        self::$directives = [];
    }

    /** Forget every registered precompiler. */
    public static function flushPrecompilers(): void
    {
        self::$precompilers = [];
    }
}
