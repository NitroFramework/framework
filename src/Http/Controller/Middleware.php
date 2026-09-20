<?php

namespace Nitro\Http\Controller;

/**
 * One middleware a controller declares, and which of its actions it covers.
 *
 * A bare string covers every action; this is for when it does not.
 *
 *     new Middleware('auth', except: ['index', 'show'])
 *     Middleware::for('verified')->only('store')
 *
 * The middleware itself is named, not passed as a closure, because the kernel
 * resolves the stack from names — an alias, a group, or a class.
 */
final class Middleware
{
    /**
     * @param string                  $middleware The alias, group or class name.
     * @param array<int, string>|null $only       Actions it applies to, or null for all.
     * @param array<int, string>|null $except     Actions it skips.
     */
    public function __construct(
        public readonly string $middleware,
        public readonly ?array $only = null,
        public readonly ?array $except = null,
    ) {
    }

    /**
     * Start from a middleware name, to be narrowed fluently.
     */
    public static function for(string $middleware): self
    {
        return new self($middleware);
    }

    /**
     * Limit it to these actions.
     *
     * @param array<int, string>|string $only
     */
    public function only(array|string $only): self
    {
        return new self($this->middleware, (array) $only, $this->except);
    }

    /**
     * Skip it for these actions.
     *
     * @param array<int, string>|string $except
     */
    public function except(array|string $except): self
    {
        return new self($this->middleware, $this->only, (array) $except);
    }

    /**
     * Whether it runs for the named action.
     */
    public function appliesTo(string $method): bool
    {
        if ($this->only !== null && ! in_array($method, $this->only, true)) {
            return false;
        }

        return $this->except === null || ! in_array($method, $this->except, true);
    }
}
