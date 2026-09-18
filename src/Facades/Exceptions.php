<?php

namespace Nitro\Facades;

/**
 * Exceptions facade — reports and renders exceptions.
 *
 *   Exceptions::report($exception);
 *   Exceptions::dontReport(ValidationException::class);
 *
 * @method static void report(\Throwable $exception)
 * @method static \Nitro\Http\Response render(\Nitro\Http\Request $request, \Throwable $exception)
 * @method static void dontReport(string|array $exceptions)
 * @method static void renderable(callable $callback)
 * @method static void reportable(callable $callback)
 */
class Exceptions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'exceptions';
    }
}
