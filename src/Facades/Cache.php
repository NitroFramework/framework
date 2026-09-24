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
    use \Nitro\Facades\Concerns\SwapsForFakes;

    protected static function getFacadeAccessor(): string
    {
        return 'cache.store';
    }

    /**
     * Cache into memory, remembering what was asked of it.
     *
     * Still a working cache, not only a recorder: code that caches usually
     * reads back what it wrote.
     */
    public static function fake(): \Nitro\Cache\CacheFake
    {
        return static::swap(new \Nitro\Cache\CacheFake());
    }
}
