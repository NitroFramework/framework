<?php

namespace Nitro\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Cache\Repository as CacheRepository;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchFactory;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Batching\DatabaseBatchRepository;
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
            BatchRepository::class,
            BatchFactory::class,
            BatchCallbacks::class,
            UniqueLock::class,
            'queue',
        ];
    }

    public function register(): void
    {
        $this->container->singleton(QueueManager::class, function ($container) {
            return new QueueManager(
                $container,
                $container->resolve(ConfigRepository::class),
            );
        });

        $this->container->alias('queue', QueueManager::class);

        $this->container->singleton(FailedJobStore::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);
            $table = $config->get('queue.failed.table');
            return new DatabaseFailedJobStore($table);
        });

        $this->container->singleton(UniqueLock::class, function ($container) {
            return new UniqueLock($container->resolve(CacheRepository::class));
        });

        $this->container->singleton(BatchFactory::class, function ($container) {
            return new BatchFactory($container->resolve(QueueManager::class));
        });

        $this->container->singleton(BatchRepository::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new DatabaseBatchRepository(
                $container->resolve(BatchFactory::class),
                $config->get('queue.batching.table') ?? 'job_batches'
            );
        });

        $this->container->singleton(BatchCallbacks::class, function ($container) {
            return new BatchCallbacks($container);
        });

        $this->container->singleton(Worker::class, function ($container) {
            return new Worker(
                $container->resolve(QueueManager::class),
                $container->resolve(FailedJobStore::class),
                $container,
                // Cache is optional — present in most apps, but the
                // worker degrades gracefully if it isn't.
                $container->has(CacheManager::class)
                    ? $container->resolve(CacheManager::class)
                    : null,
                $container->resolve(BatchRepository::class),
                $container->resolve(BatchCallbacks::class),
            );
        });
    }
}
