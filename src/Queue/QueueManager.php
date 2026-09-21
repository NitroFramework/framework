<?php

namespace Nitro\Queue;

use Closure;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Batching\PendingBatch;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Drivers\DatabaseQueue;
use Nitro\Queue\Drivers\RedisQueue;
use Nitro\Queue\Drivers\SyncQueue;
use Nitro\Redis\RedisManager;

/**
 * Resolves named queue connections from config/queue.php on demand,
 * caching each by name so a request that dispatches twice doesn't
 * reconstruct the driver twice.
 *
 *   $manager->connection();          // default
 *   $manager->connection('sync');    // tests
 *   $manager->connection('mail');    // alt connection
 *
 * Connection vs queue: a connection is the storage backend (database,
 * redis, sync, array); a queue is a named bucket WITHIN
 * that backend (default, mail, reports). One database connection
 * holds N queues.
 *
 * The manager itself doesn't push or pop — callers ask for a
 * connection, get a Queue instance, and talk to that.
 */
class QueueManager
{
    /** @var array<string, Queue> Resolved connections, keyed by name. */
    private array $connections = [];

    /**
     * Each collaborator arrives as a factory, not an instance: one connection
     * is built per request and a batch is rarer still, so nothing here should
     * construct a redis client for an application running the sync driver.
     *
     * @param Closure(): SyncQueue       $syncQueue
     * @param Closure(): RedisManager    $redis
     * @param Closure(): BatchRepository $batches
     * @param Closure(): BatchCallbacks  $batchCallbacks
     * @param ?Kernel $kernel Absent in a console command, where there is no
     *        response to wait on.
     */
    public function __construct(
        private ConfigRepository $config,
        private Closure $syncQueue,
        private Closure $redis,
        private Closure $batches,
        private Closure $batchCallbacks,
        private ?Kernel $kernel = null,
    ) {}

    public function connection(?string $name = null): Queue
    {
        $name ??= $this->config->get('queue.default');

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);
        }
        return $this->connections[$name];
    }

    /**
     * Build the driver for a named connection. Looked-up entries are
     * cached by connection(), so this is called at most once per name
     * per request.
     */
    private function resolve(string $name): Queue
    {
        $config = $this->config->get("queue.connections.{$name}");
        if (!is_array($config)) {
            throw new \RuntimeException(
                "Queue connection [{$name}] is not configured. "
                . "Add it under config/queue.php → connections."
            );
        }

        $driver = $config['driver'] ?? null;
        return match ($driver) {
            'sync'     => ($this->syncQueue)(),
            'array'    => new ArrayQueue(),
            'database' => new DatabaseQueue(
                table: $config['table'] ?? 'jobs',
                visibilityTimeout: (int) ($config['retry_after'] ?? 90),
            ),
            'redis' => new RedisQueue(
                connection: ($this->redis)()->connection($config['connection'] ?? null),
                prefix: (string) ($config['prefix'] ?? 'nitro:queue:'),
                visibilityTimeout: (int) ($config['retry_after'] ?? 90),
            ),
            default => throw new \RuntimeException(
                "Unknown queue driver [{$driver}] for connection [{$name}]."
            ),
        };
    }

    /**
     * Replace a connection with a custom instance — used by tests to
     * swap in an ArrayQueue without rewriting config.
     */
    public function extend(string $name, Queue $queue): void
    {
        $this->connections[$name] = $queue;
    }

    /**
     * Push a job onto a queue.
     *
     * @param  string|null $queue      Queue name, or the job's own.
     * @param  string|null $connection Connection name, or the default.
     * @return int|string Identifier the driver gave the queued job.
     */
    public function push(Job $job, ?string $queue = null, ?string $connection = null): int|string
    {
        $queueName = $queue ?? $job->queueName();

        $envelope = new QueuedJob(
            id: null,
            queue: $queueName,
            payload: QueuedJob::encode($job),
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        );

        return $this->connection($connection)->push($envelope, $queueName);
    }

    /**
     * Start a batch of jobs dispatched together.
     *
     *     Queue::batch([new ImportRows(1), new ImportRows(2)])->dispatch();
     *
     * @param array<int, Job>|Job $jobs
     */
    public function batch(array|Job $jobs = []): PendingBatch
    {
        return new PendingBatch(
            $this,
            ($this->batches)(),
            ($this->batchCallbacks)(),
            $jobs,
            $this->kernel,
        );
    }
}
