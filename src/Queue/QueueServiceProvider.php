<?php

namespace Nitro\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Cache\Repository as CacheRepository;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Kernel;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchFactory;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Batching\DatabaseBatchRepository;
use Nitro\Queue\Drivers\DatabaseFailedJobStore;
use Nitro\Queue\Drivers\SyncQueue;
use Nitro\Redis\RedisManager;

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
        $this->registerManager();
        $this->registerFailedJobStore();
        $this->registerUniqueLock();
        $this->registerBatching();
        $this->registerWorker();
    }

    /** The connection factory, and the 'queue' short name for it. */
    protected function registerManager(): void
    {
        $this->container->singleton(QueueManager::class, function ($container) {
            return new QueueManager(
                config: $container->resolve(ConfigRepository::class),
                syncQueue: static fn (): SyncQueue => $container->resolve(SyncQueue::class),
                redis: static fn (): RedisManager => $container->resolve(RedisManager::class),
                batches: static fn (): BatchRepository => $container->resolve(BatchRepository::class),
                batchCallbacks: static fn (): BatchCallbacks => $container->resolve(BatchCallbacks::class),
                // Absent in a console command; decided here, not looked up later.
                kernel: $container->has(Kernel::class)
                    ? $container->resolve(Kernel::class)
                    : null,
                events: $container->has('events')
                    ? $container->resolve('events')
                    : null,
            );
        });

        $this->container->alias(QueueManager::class, 'queue');
    }

    /** Where a job goes when it has exhausted its attempts. */
    protected function registerFailedJobStore(): void
    {
        $this->container->singleton(FailedJobStore::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new DatabaseFailedJobStore($config->get('queue.failed.table'));
        });
    }

    /** The cache-backed lock behind ShouldBeUnique. */
    protected function registerUniqueLock(): void
    {
        $this->container->singleton(UniqueLock::class, function ($container) {
            return new UniqueLock($container->resolve(CacheRepository::class));
        });
    }

    /** Batch storage, construction and completion callbacks. */
    protected function registerBatching(): void
    {
        $this->container->singleton(BatchFactory::class, function ($container) {
            return new BatchFactory($container->resolve(QueueManager::class));
        });

        $this->container->singleton(BatchRepository::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new DatabaseBatchRepository(
                factory: $container->resolve(BatchFactory::class),
                table: $config->get('queue.batching.table') ?? 'job_batches',
            );
        });

        $this->container->singleton(BatchCallbacks::class);
    }

    /** The process that pulls jobs off a connection and runs them. */
    protected function registerWorker(): void
    {
        $this->container->singleton(Worker::class, function ($container) {
            return new Worker(
                queues: $container->resolve(QueueManager::class),
                failedStore: $container->resolve(FailedJobStore::class),
                resolver: $container->resolve(ClassResolver::class),
                // Optional collaborators: present in most applications, but the
                // worker degrades gracefully without them. Availability is
                // decided here rather than looked up inside the worker.
                cache: $container->has(CacheManager::class)
                    ? $container->resolve(CacheManager::class)
                    : null,
                batches: $container->resolve(BatchRepository::class),
                batchCallbacks: $container->resolve(BatchCallbacks::class),
                uniqueLock: $container->has(UniqueLock::class)
                    ? $container->resolve(UniqueLock::class)
                    : null,
                events: $container->has('events')
                    ? $container->resolve('events')
                    : null,
                exceptions: $container->has(ExceptionHandler::class)
                    ? $container->resolve(ExceptionHandler::class)
                    : null,
            );
        });
    }
}
