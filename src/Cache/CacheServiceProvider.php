<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the cache manager, store and repository bindings.
 */
class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(CacheManager::class, function ($container) {
            return new CacheManager(
                config('cache', [])
            );
        });

        $this->container->alias('cache', CacheManager::class);

        $this->container->bind('cache.store', function ($container) {
            return $container->make('cache')->store();
        });

        $this->container->bind(StoreInterface::class, function ($container) {
            return $container->make('cache')->store()->getStore();
        });

        $this->container->bind(Repository::class, function ($container) {
            return $container->make('cache')->store();
        });
    }
}
