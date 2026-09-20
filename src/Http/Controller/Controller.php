<?php

namespace Nitro\Http\Controller;

use BadMethodCallException;

/**
 * The base a controller extends.
 *
 * Deliberately almost empty. Everything a controller needs is reachable
 * without inheriting it — `view()`, `redirect()`, `json()`, `abort()`,
 * `request()` are global helpers, and `DB` is a facade — so a base class that
 * wrapped them only decided, for every controller ever written, which
 * twenty-five methods it was going to have.
 *
 * What a controller wants it opts into:
 *
 *     class OrderController extends Controller
 *     {
 *         use AuthorizesRequests;
 *         use RespondsWithJson;
 *     }
 *
 * Middleware is declared by implementing {@see HasMiddleware}.
 */
abstract class Controller
{
    /**
     * Run an action, spreading the arguments the dispatcher resolved.
     *
     * The seam for anything that has to happen around every action of a
     * controller — a tenant scope, a timing span — without a middleware that
     * would also wrap the ones it does not care about.
     *
     * @param array<int, mixed> $arguments
     */
    public function callAction(string $method, array $arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }

    /**
     * Fail loudly on a call to a method that is not there.
     *
     * @param array<int, mixed> $arguments
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new BadMethodCallException(
            sprintf('Method %s::%s() does not exist.', static::class, $method)
        );
    }
}
