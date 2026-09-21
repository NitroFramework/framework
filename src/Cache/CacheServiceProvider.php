<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the cache manager, store and repository bindings.
 */
class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerManager();
        $this->registerStore();
        $this->registerRepository();
        $this->registerRateLimiter();
    }

    /** The store factory, and the 'cache' short name for it. */
    protected function registerManager(): void
    {
        $this->container->singleton(CacheManager::class, function ($container) {
            return new CacheManager(
                (array) $container->resolve(ConfigRepository::class)->get('cache', [])
            );
        });

        $this->container->alias('cache', CacheManager::class);
    }

    /** The default store, and the raw driver behind it. */
    protected function registerStore(): void
    {
        $this->container->bind('cache.store', function ($container) {
            return $container->resolve('cache')->store();
        });

        $this->container->bind(StoreInterface::class, function ($container) {
            return $container->resolve('cache')->store()->getStore();
        });
    }

    /** The default store, wired to the event bus it raises on. */
    protected function registerRepository(): void
    {
        $this->container->bind(Repository::class, function ($container) {
            $repository = $container->resolve('cache')->store();

            /*
             * The repository raises cache.hit/missed/written/forgotten, so it
             * needs the bus. Done here because the manager builds repositories
             * on demand, one per store — there is no single instance for a
             * provider's boot() to reach.
             */
            if ($repository instanceof ReceivesDispatcher) {
                $repository->setDispatcher($container->resolve(EventDispatcher::class));
            }

            return $repository;
        });
    }

    /** Cache-backed rate limiter (login lockout, throttle middleware, …). */
    protected function registerRateLimiter(): void
    {
        $this->container->singleton(RateLimiter::class, function ($container) {
            return new RateLimiter($container->resolve(Repository::class));
        });
    }
}
