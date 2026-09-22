<?php

namespace Nitro\Http\Middleware;

use Closure;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Support\Arr;
use Nitro\Support\Str;

/**
 * Strips surrounding whitespace from every string in a request.
 *
 * A trailing space in an email field is invisible to whoever typed it and
 * fatal to a uniqueness check, so this runs before anything reads the input
 * rather than being remembered at each use.
 */
class TrimStrings extends TransformsRequest
{
    /**
     * Fields left alone.
     *
     * A password may legitimately begin or end with a space, and trimming one
     * would silently change a credential — the user would be locked out of an
     * account they typed correctly.
     *
     * @var array<int, string>
     */
    protected array $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /** @var array<int, string> Added by an application, for every instance. */
    protected static array $neverTrim = [];

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
        $except = array_merge($this->except, static::$neverTrim);

        if ($this->shouldSkip($key, $except) || ! is_string($value)) {
            return $value;
        }

        return Str::trim($value);
    }

    /**
     * Patterns are matched, not compared, so 'secrets.*' covers a whole group.
     *
     * @param array<int, string> $except
     */
    protected function shouldSkip(string $key, array $except): bool
    {
        return Str::is($except, $key);
    }

    /**
     * Never trim these fields, in any instance of this middleware.
     *
     * @param array<int, string>|string $attributes
     */
    public static function except(array|string $attributes): void
    {
        static::$neverTrim = array_values(array_unique(
            array_merge(static::$neverTrim, Arr::wrap($attributes))
        ));
    }

    /** Skip the whole middleware when the callback says so. */
    public static function skipWhen(Closure $callback): void
    {
        static::$skipCallbacks[] = $callback;
    }

    /** Drop the global state, so one test cannot leak into the next. */
    public static function flushState(): void
    {
        static::$neverTrim = [];
        static::$skipCallbacks = [];
    }
}
