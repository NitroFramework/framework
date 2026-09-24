<?php

namespace Tests\Unit\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Foundation\Config;
use Nitro\Queue\Batchable;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Batching\NullBatchRepository;
use Nitro\Queue\Batching\PendingBatch;
use Nitro\Queue\Batching\UpdatedBatchJobCounts;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Events;
use Nitro\Queue\Job;
use Nitro\Queue\QueueManager;
use Nitro\Queue\Worker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Batch events raised by real processing, and a worker that is actually paused.
 *
 * Both were wired in without being run: an event that is constructed but never
 * reached, and a flag the worker reads but nothing had proved it acts on. The
 * point of these is to run the worker and watch.
 */
class BatchEventsAndPauseTest extends TestCase
{
    private Container $container;

    private QueueManager $queues;

    private ArrayQueue $array;

    private CountingBatchRepository $batches;

    /** @var array<int, object> */
    private array $raised = [];

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());

        $this->container = Container::getInstance();
        $this->raised = [];

        CountedTrace::$ran = [];

        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => ['array' => ['driver' => 'array']],
            ],
        ]);

        $this->container->instance(Config::class, $config);

        $recorder = new class ($this->raised) implements Dispatcher {
            /** @param array<int, object> $raised */
            public function __construct(public array &$raised) {}

            public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
            {
                $this->raised[] = $event;

                return null;
            }

            public function listen(string|array $events, callable|string $listener): void {}
            public function subscribe(string|object $subscriber): void {}
            public function until(string|object $event, mixed $payload = []): mixed { return null; }
            public function forget(string $event): void {}
            public function hasListeners(string $event): bool { return false; }
        };

        $this->batches = new CountingBatchRepository();

        $unused = static fn (): never => throw new RuntimeException('not reached in this test');

        $this->queues = new QueueManager(
            $config,
            $unused,
            $unused,
            fn (): BatchRepository => $this->batches,
            fn (): BatchCallbacks => new BatchCallbacks($this->container->resolve(ClassResolver::class)),
            null,
            $recorder,
        );

        $this->batches->attach($this->queues);

        $this->array = new ArrayQueue();
        $this->queues->extend('array', $this->array);

        $this->container->instance('queue', $this->queues);
        $this->container->instance(QueueManager::class, $this->queues);
        $this->container->instance(BatchRepository::class, $this->batches);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    private function worker(?CacheManager $cache = null): Worker
    {
        return new Worker(
            $this->queues,
            new InMemoryFailedJobStore(),
            $this->container->resolve(ClassResolver::class),
            $cache,
            $this->batches,
        );
    }

    /** @return array<int, class-string> */
    private function raisedClasses(): array
    {
        return array_map(static fn (object $e): string => $e::class, $this->raised);
    }

    private function drain(Worker $worker, int $limit = 20): void
    {
        $ran = 0;

        while ($this->array->size('default') > 0 && $ran < $limit) {
            $worker->run([
                'connection' => 'array',
                'queue' => 'default',
                'once' => true,
                'sleep' => 0,
                'tries' => 1,
            ]);

            $ran++;
        }
    }

    // ─── Batch events, raised by real work ────────────────

    public function test_dispatching_a_batch_raises_the_dispatched_event(): void
    {
        $this->queues->batch([new CountedJob('a'), new CountedJob('b')])->dispatch();

        $this->assertContains(Events\BatchDispatched::class, $this->raisedClasses());
    }

    /**
     * Started is not the same as dispatched: a batch can sit queued for a long
     * time before a worker reaches it, and this is the point it starts moving.
     */
    public function test_the_first_processed_job_raises_started_and_the_last_raises_finished(): void
    {
        $this->queues->batch([new CountedJob('a'), new CountedJob('b')])->dispatch();

        $this->raised = [];

        $this->drain($this->worker());

        $classes = $this->raisedClasses();

        $this->assertContains(Events\BatchStarted::class, $classes);
        $this->assertContains(Events\BatchFinished::class, $classes);

        $this->assertLessThan(
            array_search(Events\BatchFinished::class, $classes, true),
            array_search(Events\BatchStarted::class, $classes, true),
            'started comes before finished'
        );
    }

    public function test_started_is_raised_once_not_per_job(): void
    {
        $this->queues->batch([new CountedJob('a'), new CountedJob('b'), new CountedJob('c')])->dispatch();

        $this->raised = [];

        $this->drain($this->worker());

        $started = array_filter(
            $this->raisedClasses(),
            static fn (string $class): bool => $class === Events\BatchStarted::class,
        );

        $this->assertCount(1, $started);
    }

    public function test_a_failing_job_cancels_the_batch_and_says_so(): void
    {
        $this->queues->batch([new FailingCountedJob(), new CountedJob('b')])->dispatch();

        $this->raised = [];

        $this->drain($this->worker());

        $this->assertContains(Events\BatchCanceled::class, $this->raisedClasses());
    }

    /** The exception that cancelled it travels with the event. */
    public function test_the_cancel_event_carries_the_exception(): void
    {
        $this->queues->batch([new FailingCountedJob()])->dispatch();

        $this->drain($this->worker());

        foreach ($this->raised as $event) {
            if ($event instanceof Events\BatchCanceled) {
                $this->assertInstanceOf(RuntimeException::class, $event->exception);
                $this->assertSame('batch boom', $event->exception->getMessage());

                return;
            }
        }

        $this->fail('no BatchCanceled was raised');
    }

    public function test_a_batch_that_allows_failures_is_not_cancelled(): void
    {
        $this->queues->batch([new FailingCountedJob()])->allowFailures()->dispatch();

        $this->raised = [];

        $this->drain($this->worker());

        $this->assertNotContains(Events\BatchCanceled::class, $this->raisedClasses());
    }

    // ─── Pausing a worker that is running ─────────────────

    private function cache(): CacheManager
    {
        return new CacheManager(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]);
    }

    /** A paused worker leaves the work where it is. */
    public function test_a_paused_worker_takes_nothing(): void
    {
        $cache = $this->cache();
        $cache->put('queue:paused', time(), 60);

        $this->queues->push(new CountedJob('a'), 'default', 'array');

        $this->assertSame(1, $this->array->size('default'));

        $this->worker($cache)->run([
            'connection' => 'array',
            'queue' => 'default',
            'once' => true,
            'sleep' => 0,
        ]);

        $this->assertSame([], CountedTrace::$ran, 'the job did not run');
        $this->assertSame(1, $this->array->size('default'), 'and it is still queued');
    }

    public function test_the_same_worker_takes_work_once_resumed(): void
    {
        $cache = $this->cache();
        $cache->put('queue:paused', time(), 60);

        $this->queues->push(new CountedJob('a'), 'default', 'array');

        $worker = $this->worker($cache);

        $worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame(1, $this->array->size('default'));

        $cache->forget('queue:paused');

        $worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame(['a'], CountedTrace::$ran);
        $this->assertSame(0, $this->array->size('default'));
    }

    /** A pause on another connection must not stop this one. */
    public function test_a_pause_elsewhere_does_not_stop_this_worker(): void
    {
        $cache = $this->cache();
        $cache->put('queue:paused:redis', time(), 60);

        $this->queues->push(new CountedJob('a'), 'default', 'array');

        $this->worker($cache)->run([
            'connection' => 'array',
            'queue' => 'default',
            'once' => true,
            'sleep' => 0,
        ]);

        $this->assertSame(['a'], CountedTrace::$ran);
    }

    public function test_a_pause_on_this_queue_stops_it(): void
    {
        $cache = $this->cache();
        $cache->put('queue:paused:array:default', time(), 60);

        $this->queues->push(new CountedJob('a'), 'default', 'array');

        $this->worker($cache)->run([
            'connection' => 'array',
            'queue' => 'default',
            'once' => true,
            'sleep' => 0,
        ]);

        $this->assertSame([], CountedTrace::$ran);
        $this->assertSame(1, $this->array->size('default'));
    }

    /** No cache means no pause flag to read, and the worker simply runs. */
    public function test_a_worker_without_a_cache_is_never_paused(): void
    {
        $this->queues->push(new CountedJob('a'), 'default', 'array');

        $this->worker(null)->run([
            'connection' => 'array',
            'queue' => 'default',
            'once' => true,
            'sleep' => 0,
        ]);

        $this->assertSame(['a'], CountedTrace::$ran);
    }
}

/** A batch store that keeps real counters, so a batch can actually settle. */
class CountingBatchRepository extends NullBatchRepository
{
    /** @var array<string, array{total: int, pending: int, failed: int}> */
    private array $counts = [];

    public function attach(QueueManager $queue): void
    {
        $this->queue = $queue;
    }

    public function store(PendingBatch $batch): \Nitro\Queue\Batching\Batch
    {
        $stored = parent::store($batch);

        $this->counts[$stored->id] = [
            'total' => $stored->totalJobs,
            'pending' => $stored->pendingJobs,
            'failed' => 0,
        ];

        return $stored;
    }

    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $this->counts[$batchId]['pending'] = max(0, $this->counts[$batchId]['pending'] - 1);

        return new UpdatedBatchJobCounts(
            $this->counts[$batchId]['pending'],
            $this->counts[$batchId]['failed'],
        );
    }

    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $this->counts[$batchId]['pending'] = max(0, $this->counts[$batchId]['pending'] - 1);
        $this->counts[$batchId]['failed']++;

        return new UpdatedBatchJobCounts(
            $this->counts[$batchId]['pending'],
            $this->counts[$batchId]['failed'],
        );
    }
}

class CountedJob extends Job
{
    use Batchable;

    protected int $tries = 1;

    public function __construct(public string $tag = '')
    {
    }

    public function handle(): void
    {
        CountedTrace::$ran[] = $this->tag;
    }
}

class FailingCountedJob extends Job
{
    use Batchable;

    protected int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('batch boom');
    }
}

class CountedTrace
{
    /** @var array<int, string> */
    public static array $ran = [];
}
