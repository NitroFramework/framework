<?php

namespace Nitro\Facades;

use Nitro\Container\Container;

/**
 * Base class for static facades — Laravel's Facade pattern.
 *
 * A subclass names the container service it proxies via accessor(); every
 * static call is forwarded to that resolved instance:
 *
 *   Auth::user()      → container->get('auth')->user()
 *   Cache::get('k')   → container->get('cache.store')->get('k')
 */
abstract class Facade
{
    /** The container binding this facade resolves. */
    abstract protected static function getFacadeAccessor(): string;

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return Container::getInstance()->get(static::getFacadeAccessor())->{$method}(...$arguments);
    }
}
