<?php

namespace Nitro\Facades;

/**
 * Cache facade — Laravel's `Cache::`. Proxies the default store.
 *
 *   Cache::get('key');  Cache::put('key', $v, 60);  Cache::remember('k', 60, fn() => ...);
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool  put(string $key, mixed $value, int $seconds = null)
 * @method static bool  has(string $key)
 * @method static bool  forget(string $key)
 * @method static mixed remember(string $key, int $seconds, \Closure $callback)
 * @method static bool  flush()
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cache.store';
    }
}
