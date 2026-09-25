<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the cache manager, store and repository bindings.
 *
 * Each binding names the method that builds it rather than carrying a closure
 * inline, so register() reads as what this provider offers and every how has
 * a name and a docblock of its own.
 */
class CacheServiceProvider extends ServiceProvider
{
    /** Deferred: the cache layer loads when something first caches. */
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [
            CacheManager::class,
            'cache',
            'cache.store',
            StoreInterface::class,
            Repository::class,
            RateLimiter::class,
        ];
    }

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
        $this->container->singleton(CacheManager::class, $this->createManager(...));

        $this->container->alias(CacheManager::class, 'cache');
    }

    /** The default store, and the raw driver behind it. */
    protected function registerStore(): void
    {
        $this->container->bind('cache.store', $this->createStore(...), true);
        $this->container->bind(StoreInterface::class, $this->createDriver(...), true);
    }

    /** The default store, under the contract callers inject. */
    protected function registerRepository(): void
    {
        $this->container->bind(Repository::class, $this->createStore(...), true);
    }

    /** Cache-backed rate limiter (login lockout, throttle middleware, …). */
    protected function registerRateLimiter(): void
    {
        $this->container->singleton(RateLimiter::class, $this->createRateLimiter(...));
    }

    /**
     * Built from the `cache` section: the default store name, prefix and store map.
     *
     * The bus goes in as a factory rather than an instance: the manager wires
     * every store it builds, and an application that caches nothing should not
     * build an event dispatcher on its account.
     */
    protected function createManager(Container $container): CacheManager
    {
        $config = $container->resolve(ConfigRepository::class);

        return new CacheManager(
            config: (array) $config->get('cache', []),
            dispatcher: static fn (): EventDispatcher => $container->resolve(EventDispatcher::class),
        );
    }

    /** The repository for the default store. */
    protected function createStore(Container $container): Repository
    {
        return $container->resolve('cache')->store();
    }

    /** The driver underneath the default store, for a caller that wants it raw. */
    protected function createDriver(Container $container): StoreInterface
    {
        return $container->resolve('cache')->store()->getStore();
    }

    protected function createRateLimiter(Container $container): RateLimiter
    {
        return new RateLimiter($container->resolve(Repository::class));
    }
}
