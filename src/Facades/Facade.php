<?php

namespace Nitro\Facades;

/**
 * Base class for static facades — Laravel's Facade pattern.
 *
 * A subclass names the container service it proxies via accessor(); every
 * static call is forwarded to that resolved instance:
 *
 *   Auth::user()      → app('auth')->user()
 *   Cache::get('k')   → app('cache.store')->get('k')
 */
abstract class Facade
{
    /** The container binding this facade resolves. */
    abstract protected static function getFacadeAccessor(): string;

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::getFacadeAccessor())->{$method}(...$arguments);
    }
}
