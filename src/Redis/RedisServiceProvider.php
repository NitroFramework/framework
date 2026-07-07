<?php

namespace Nitro\Redis;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the RedisManager as the shared 'redis' service. Connections come from
 * config('database.redis'); nothing is hardcoded.
 */
class RedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('redis', function () {
            return new RedisManager((array) config('database.redis', []));
        });

        $this->container->alias(RedisManager::class, 'redis');
    }
}
