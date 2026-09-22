<?php

namespace Nitro\Http\Middleware;

use Closure;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Turns an empty submitted field into null.
 *
 * A browser sends an untouched text input as an empty string, not as nothing,
 * so without this a nullable column receives '' and a `nullable` rule passes
 * a value that was never entered. Converting once, here, means the rest of
 * the application only has to reason about null.
 */
class ConvertEmptyStringsToNull extends TransformsRequest
{
    /** @var array<int, Closure> */
    protected static array $skipCallbacks = [];

    public function handle(Request $request, callable $next): Response
    {
        foreach (static::$skipCallbacks as $callback) {
            if ($callback($request)) {
                return $next($request);
            }
        }

        return parent::handle($request, $next);
    }

    protected function transform(string $key, mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /** Skip the whole middleware when the callback says so. */
    public static function skipWhen(Closure $callback): void
    {
        static::$skipCallbacks[] = $callback;
    }

    /** Drop the global state, so one test cannot leak into the next. */
    public static function flushState(): void
    {
        static::$skipCallbacks = [];
    }
}
