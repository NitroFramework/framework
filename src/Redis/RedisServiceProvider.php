<?php

namespace Nitro\Redis;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the RedisManager as the shared 'redis' service. Connections come from
 * config('database.redis'); nothing is hardcoded.
 */
class RedisServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['redis', RedisManager::class];
    }

    public function register(): void
    {
        $this->container->singleton('redis', function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new RedisManager((array) $config->get('database.redis', []));
        });

        $this->container->alias(RedisManager::class, 'redis');
    }
}
