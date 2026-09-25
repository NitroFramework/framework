<?php

namespace Nitro\Components;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\MemcachedConnector;
use Illuminate\Cache\RateLimiter;
use Nitro\Foundation\Application;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

/**
 * CacheServiceProvider, wired directly. CacheManager is kept (rather than building the store
 * by hand) because packages call Cache::store($name) and type-hint the Factory contract; its
 * constructor is a single assignment, so there is nothing to gain by skipping it.
 */
final class Cache
{
    public static function manager(Application $app): CacheManager
    {
        return new CacheManager($app);
    }

    public static function store(Application $app): mixed
    {
        return $app->make('cache')->driver();
    }

    public static function psr6(Application $app): Psr16Adapter
    {
        return new Psr16Adapter($app->make('cache.store'));
    }

    public static function memcachedConnector(): MemcachedConnector
    {
        return new MemcachedConnector;
    }

    public static function rateLimiter(Application $app): RateLimiter
    {
        return new RateLimiter($app->make('cache')->driver($app->make('config')->get('cache.limiter')));
    }
}
