<?php

namespace Nitro\Queue;

use Closure;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Queue\Batching\Batch;
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
        private ?EventDispatcher $events = null,
    ) {}

    public function connection(?string $name = null): Queue
    {
        // Falling back by name rather than passing null through: an unset
        // queue.default used to reach resolve() as null and fail with a
        // TypeError, where resolve() exists to say which connection is
        // missing and where to configure it.
        $name ??= (string) ($this->config->get('queue.default') ?? 'sync');

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name)->setConnectionName($name);
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
        $this->connections[$name] = $queue->setConnectionName($name);
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
        return $this->dispatch($job, $queue, $connection, 0);
    }

    /**
     * Push a job that only becomes runnable after a delay.
     *
     * @param  int         $delay      Seconds from now.
     * @param  string|null $queue      Queue name, or the job's own.
     * @param  string|null $connection Connection name, or the default.
     * @return int|string Identifier the driver gave the queued job.
     */
    public function later(int $delay, Job $job, ?string $queue = null, ?string $connection = null): int|string
    {
        return $this->dispatch($job, $queue, $connection, max(0, $delay));
    }

    /**
     * Wrap a job in an envelope and hand it to a connection.
     *
     * Every dispatch path runs through here, so the queueing events
     * fire once wherever a job entered from.
     *
     * Everything but the job is optional: push() and later() fill all four in,
     * but Bus::dispatch($job) and Queue::dispatch($job) are how a job is
     * dispatched by hand, and requiring the queue, the connection and the
     * delay at those call sites made the documented one-argument form an
     * ArgumentCountError. A null queue means the job's own.
     */
    public function dispatch(
        Job $job,
        ?string $queue = null,
        ?string $connection = null,
        int $delay = 0,
    ): int|string {
        $queueName = $queue ?? $job->queueName();
        $now = time();

        $envelope = new QueuedJob(
            id: null,
            queue: $queueName,
            payload: QueuedJob::encode($job),
            attempts: 0,
            availableAt: $now + $delay,
            reservedAt: null,
            createdAt: $now,
        );

        $driver = $this->connection($connection);
        $name = $driver->getConnectionName();

        $this->events?->dispatch(new Events\JobQueueing($envelope, $name, $job, $delay));

        $id = $delay > 0
            ? $driver->later($delay, $envelope, $queueName)
            : $driver->push($envelope, $queueName);

        $this->events?->dispatch(new Events\JobQueued($envelope, $name, $job, $delay));

        return $id;
    }

    /** Report that a unique job was dropped rather than queued. */
    public function reportSkipped(Job $job): void
    {
        $this->events?->dispatch(new Events\UniqueJobSkipped($job));
    }

    /**
     * Announce something on the application's dispatcher.
     *
     * For the objects the manager hands out — a Batch, most of it — which have
     * something to say and no reason to be given a dispatcher of their own. A
     * manager built without one raises nothing rather than failing.
     */
    public function raise(object $event): void
    {
        $this->events?->dispatch($event);
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
            queue: $this,
            repository: ($this->batches)(),
            callbacks: ($this->batchCallbacks)(),
            jobs: $jobs,
            kernel: $this->kernel,
        );
    }

    /**
     * Run jobs one after another, each only if the one before it succeeded.
     *
     *     Queue::chain([new PullOrders(), new Reconcile()])->dispatch();
     *
     * Unlike a batch, only the first job is queued: it carries the rest and
     * queues the next itself once it has finished. So a failure stops the
     * chain, where a batch's other jobs would already be on the queue.
     *
     * @param array<int, Job> $jobs
     */
    public function chain(array $jobs): PendingChain
    {
        return new PendingChain($this, $jobs);
    }

    /**
     * Look up a batch by its identifier.
     *
     * For a progress endpoint, which has the id from the client and nothing
     * else to go on.
     */
    public function findBatch(string $batchId): ?Batch
    {
        return ($this->batches)()->find($batchId);
    }

    /**
     * Queue several jobs at once.
     *
     * They are independent — no order, no shared state. A batch is the one
     * with callbacks and counters, and a chain the one with order.
     *
     * @param array<int, Job> $jobs
     * @return array<int, int|string> The ids, in the order given.
     */
    public function bulk(array $jobs, ?string $queue = null, ?string $connection = null): array
    {
        return array_map(
            fn (Job $job): int|string => $this->dispatch($job, $queue, $connection),
            array_values($jobs),
        );
    }

    /**
     * Queue a job once the response has been sent.
     *
     * The caller does not wait for the push, which is worth having when the
     * queue is a network hop away. Without a kernel to wait on — a console
     * command — it is dispatched now rather than dropped.
     */
    public function dispatchAfterResponse(Job $job, ?string $queue = null, ?string $connection = null): void
    {
        if ($this->kernel === null) {
            $this->dispatch($job, $queue, $connection);

            return;
        }

        $this->kernel->terminating(function () use ($job, $queue, $connection): void {
            $this->dispatch($job, $queue, $connection);
        });
    }
}
