<?php

namespace Nitro\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Drivers\DatabaseFailedJobStore;

/**
 * Wires the queue layer into the container. Bind three things:
 *   - QueueManager     (singleton; resolves connections from config)
 *   - FailedJobStore   (singleton; defaults to the database-backed store)
 *   - Worker           (singleton; depends on the two above)
 *
 * All three are deferred — bindings load when first resolved, so an app
 * that never queues anything pays nothing. The deferred-provider path
 * in the container reads provides() to know what triggers load.
 */
class QueueServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    public function provides(): array
    {
        return [
            QueueManager::class,
            FailedJobStore::class,
            Worker::class,
            'queue',
        ];
    }

    public function register(): void
    {
        $this->container->singleton(QueueManager::class, function ($container) {
            return new QueueManager(
                $container,
                $container->get(ConfigRepository::class),
            );
        });

        $this->container->alias('queue', QueueManager::class);

        $this->container->singleton(FailedJobStore::class, function ($container) {
            $config = $container->get(ConfigRepository::class);
            $table = $config->get('queue.failed.table');
            return new DatabaseFailedJobStore($table);
        });

        $this->container->singleton(Worker::class, function ($container) {
            return new Worker(
                $container->get(QueueManager::class),
                $container->get(FailedJobStore::class),
                $container,
                // Cache is optional — present in most apps, but the
                // worker degrades gracefully if it isn't.
                $container->has(CacheManager::class)
                    ? $container->get(CacheManager::class)
                    : null,
            );
        });
    }
}
