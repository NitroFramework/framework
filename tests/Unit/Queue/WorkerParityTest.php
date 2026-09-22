<?php

namespace Tests\Unit\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Foundation\Config;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Events;
use Nitro\Queue\InteractsWithQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use Nitro\Queue\QueueManager;
use Nitro\Queue\Worker;
use Nitro\Queue\WorkerOptions;
use Nitro\Queue\WorkerStopReason;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * What the worker does around a job, rather than with it.
 *
 * The cases here are the ones that only show up in a running worker:
 * a job that put itself back on the queue, a retry budget measured in
 * time rather than attempts, a queue named second in a priority list.
 * Each was reachable in the layer's design and none was exercised —
 * several were wrong because of it.
 */
class WorkerParityTest extends TestCase
{
    private Container $container;
    private QueueManager $queues;
    private ArrayQueue $array;
    private ParityFailedStore $failed;
    private RecordingDispatcher $events;

    protected function setUp(): void
    {
        Container::setInstance(new Container());
        $this->container = Container::getInstance();
        ParityRecorder::reset();

        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => ['array' => ['driver' => 'array']],
            ],
        ]);
        $this->container->instance(Config::class, $config);

        $this->events = new RecordingDispatcher();

        $this->queues = new QueueManager(
            config: $config,
            syncQueue: static fn () => throw new \RuntimeException('not used'),
            redis: static fn () => throw new \RuntimeException('not used'),
            batches: static fn () => throw new \RuntimeException('not used'),
            batchCallbacks: static fn () => throw new \RuntimeException('not used'),
            kernel: null,
            events: $this->events,
        );

        $this->array = new ArrayQueue();
        $this->queues->extend('array', $this->array);

        $this->failed = new ParityFailedStore();
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    private function worker(?CacheManager $cache = null): Worker
    {
        return new Worker(
            queues: $this->queues,
            failedStore: $this->failed,
            resolver: $this->container->resolve(ClassResolver::class),
            cache: $cache,
            events: $this->events,
        );
    }

    private function push(Job $job, string $queue = 'default', int $attempts = 0): QueuedJob
    {
        $envelope = new QueuedJob(
            id: null,
            queue: $queue,
            payload: QueuedJob::encode($job),
            attempts: $attempts,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        );

        $this->array->push($envelope, $queue);

        return $envelope;
    }

    /** @param array<string, mixed> $options */
    private function work(array $options = []): int
    {
        return $this->worker()->run($options + [
            'connection' => 'array',
            'queue' => 'default',
            'once' => true,
            'sleep' => 0,
        ]);
    }

    // ── A job that acted on its own place in the queue ─────────────────

    /**
     * A job that released itself must not then be deleted.
     *
     * The worker deleted every job that returned without throwing, so
     * release() inside handle() put the job back and the delete on the
     * next line took it away again — the retry was written and dropped
     * in the same breath, and the work was simply lost.
     */
    public function test_a_job_that_releases_itself_stays_on_the_queue(): void
    {
        $this->push(new SelfReleasingJob());

        $this->work();

        $this->assertSame(1, $this->array->size(), 'the released job must still be queued');
        $this->assertSame(0, $this->failed->count());
    }

    /** A job that deleted itself is not deleted twice, nor recorded as failed. */
    public function test_a_job_that_deletes_itself_is_left_alone(): void
    {
        $this->push(new SelfDeletingJob());

        $this->work();

        $this->assertSame(0, $this->array->size());
        $this->assertSame(0, $this->failed->count());
    }

    /** A job that failed itself is not also released for another attempt. */
    public function test_a_job_that_fails_itself_is_not_released(): void
    {
        $this->push(new SelfFailingJob());

        $this->work();

        $this->assertSame(0, $this->array->size(), 'a failed job must not be queued again');
    }

    // ── Priority queues ───────────────────────────────────────────────

    /**
     * Several queues in one string are an order, not a set.
     *
     * The worker took the name as a single queue, so `--queue=high,low`
     * asked for a queue called "high,low" and found nothing — a worker
     * configured for priorities processed no jobs at all.
     */
    public function test_queues_are_drained_in_the_order_they_are_named(): void
    {
        $this->push(new ParityJob('low'), 'low');
        $this->push(new ParityJob('high'), 'high');

        $this->work(['queue' => 'high,low']);
        $this->work(['queue' => 'high,low']);

        $this->assertSame(['high', 'low'], ParityRecorder::$ran);
    }

    public function test_a_later_queue_is_reached_when_the_first_is_empty(): void
    {
        $this->push(new ParityJob('only'), 'low');

        $this->work(['queue' => 'high,low']);

        $this->assertSame(['only'], ParityRecorder::$ran);
    }

    // ── Retry budgets ─────────────────────────────────────────────────

    /**
     * A time budget replaces the attempt count rather than capping it.
     *
     * A job worth retrying for an hour should not stop after three
     * quick failures, which is the whole point of asking for one.
     */
    public function test_retry_until_keeps_a_job_alive_past_its_attempt_cap(): void
    {
        $this->push(new RetryUntilJob(), attempts: 10);

        $this->work();

        $this->assertSame(0, $this->failed->count(), 'the deadline has not passed');
        $this->assertSame(1, $this->array->size(), 'so the job is queued again');
    }

    public function test_retry_until_fails_the_job_once_the_deadline_passes(): void
    {
        $this->push(new ExpiredRetryUntilJob());

        $this->work();

        $this->assertSame(1, $this->failed->count(), 'past the deadline the job is done');
        $this->assertSame(0, $this->array->size());
    }

    /**
     * Exceptions are counted separately from attempts.
     *
     * A job that releases itself can attempt many times without ever
     * throwing, so the attempt cap never catches it; this is the cap
     * on the throws themselves.
     */
    public function test_max_exceptions_fails_a_job_before_its_attempts_run_out(): void
    {
        $cache = new CacheManager([
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
        ]);

        $envelope = $this->push(new MaxExceptionsJob());
        $worker = $this->worker($cache);

        // Two throws against a cap of two, with twenty attempts allowed.
        for ($i = 0; $i < 2; $i++) {
            $worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);
        }

        $this->assertSame(1, $this->failed->count(), 'the second throw spends the budget');
        $this->assertSame(0, $this->array->size());
        $this->assertNotNull($envelope->uuid(), 'the count needs an identifier that survives releases');
    }

    // ── Backoff ───────────────────────────────────────────────────────

    /**
     * A backoff list is read by attempt.
     *
     * A schedule that starts fast and backs off is the common case,
     * and writing it as a list means the job needs no arithmetic.
     */
    public function test_a_backoff_list_gives_each_attempt_its_own_wait(): void
    {
        $this->push(new BackoffListJob());

        $this->work();

        $this->assertSame(1, $this->releasedFor(), 'the first attempt waits one second');
    }

    public function test_the_last_backoff_value_covers_every_later_attempt(): void
    {
        $this->push(new BackoffListJob(), attempts: 8);

        $this->work();

        $this->assertSame(60, $this->releasedFor(), 'past the list, its last value repeats');
    }

    /** How long the worker put the job back for. */
    private function releasedFor(): ?int
    {
        return $this->events->first(Events\JobReleasedAfterException::class)?->delay;
    }

    // ── Stopping ──────────────────────────────────────────────────────

    public function test_the_worker_reports_why_it_stopped(): void
    {
        $this->push(new ParityJob('a'));
        $this->push(new ParityJob('b'));

        $status = $this->worker()->daemon('array', 'default', new WorkerOptions(
            sleep: 0,
            maxJobs: 1,
        ));

        $this->assertSame(Worker::EXIT_SUCCESS, $status);

        $stopping = $this->events->first(Events\WorkerStopping::class);

        $this->assertNotNull($stopping);
        $this->assertSame(WorkerStopReason::MaxJobsExceeded, $stopping->reason);
        $this->assertSame(1, $stopping->jobsProcessed);
    }

    public function test_an_empty_queue_stops_a_worker_told_to(): void
    {
        $status = $this->worker()->daemon('array', 'default', new WorkerOptions(
            sleep: 0,
            stopWhenEmpty: true,
        ));

        $this->assertSame(Worker::EXIT_SUCCESS, $status);
        $this->assertSame(
            WorkerStopReason::QueueEmpty,
            $this->events->first(Events\WorkerStopping::class)?->reason,
        );
    }

    /**
     * Running out of memory is not a success.
     *
     * A supervisor reads the exit code: restarting on 0 is routine, and
     * a worker recycling on memory every few seconds should be visible
     * as something other than a clean exit.
     */
    public function test_exceeding_memory_exits_with_its_own_code(): void
    {
        $status = $this->worker()->daemon('array', 'default', new WorkerOptions(
            memory: 1,
            sleep: 0,
        ));

        $this->assertSame(Worker::EXIT_MEMORY_LIMIT, $status);
        $this->assertSame(
            WorkerStopReason::MaxMemoryExceeded,
            $this->events->first(Events\WorkerStopping::class)?->reason,
        );
    }

    // ── Events ────────────────────────────────────────────────────────

    /**
     * The lifecycle events are dispatched, not merely declared.
     *
     * Sixteen event classes shipped and five were ever fired; the rest
     * were classes a listener could subscribe to and never hear from.
     */
    public function test_the_worker_fires_its_lifecycle_events(): void
    {
        $this->push(new ParityJob('x'));

        $this->work();

        foreach ([
            Events\WorkerStarting::class,
            Events\Looping::class,
            Events\JobPopping::class,
            Events\JobPopped::class,
            Events\JobProcessing::class,
            Events\JobProcessed::class,
            Events\JobAttempted::class,
            Events\WorkerStopping::class,
        ] as $event) {
            $this->assertNotNull($this->events->first($event), $event . ' was never dispatched');
        }
    }

    public function test_an_idle_worker_says_so(): void
    {
        $this->work();

        $this->assertNotNull($this->events->first(Events\WorkerIdle::class));
    }

    /** A failing job reports the attempt and what it was released for. */
    public function test_a_failing_job_reports_its_release(): void
    {
        $this->push(new ParityFailingJob());

        $this->work(['tries' => 5]);

        $released = $this->events->first(Events\JobReleasedAfterException::class);

        $this->assertNotNull($released);
        $this->assertInstanceOf(\RuntimeException::class, $released->exception);

        $attempted = $this->events->first(Events\JobAttempted::class);

        $this->assertNotNull($attempted);
        $this->assertFalse($attempted->successful(), 'the attempt carries the exception');
    }

    /** Queueing a job is announced on both sides of the push. */
    public function test_pushing_a_job_fires_the_queueing_events(): void
    {
        $this->queues->push(new ParityJob('queued'));

        $this->assertNotNull($this->events->first(Events\JobQueueing::class));

        $queued = $this->events->first(Events\JobQueued::class);

        $this->assertNotNull($queued);
        $this->assertNotNull($queued->id(), 'the identifier is known by the time it is announced');
        $this->assertSame('array', $queued->connectionName);
    }

    /**
     * A listener can hold the worker back for a turn.
     *
     * Returning false from Looping is how work is kept off a queue
     * during a migration without stopping the process and losing
     * whatever it had reserved.
     */
    public function test_a_looping_listener_can_hold_the_worker_back(): void
    {
        $this->push(new ParityJob('held'));

        $this->events->answer(Events\Looping::class, false);

        $this->worker()->daemon('array', 'default', new WorkerOptions(sleep: 0, maxTime: 0, stopWhenEmpty: false, maxJobs: 0, memory: 1));

        $this->assertSame([], ParityRecorder::$ran, 'nothing ran while the worker was held');
        $this->assertSame(1, $this->array->size());
    }

    // ── Connection identity ───────────────────────────────────────────

    public function test_a_connection_knows_its_own_name(): void
    {
        $this->assertSame('array', $this->queues->connection('array')->getConnectionName());
    }

    public function test_events_name_the_connection_a_job_came_from(): void
    {
        $this->push(new ParityJob('named'));

        $this->work();

        $this->assertSame('array', $this->events->first(Events\JobProcessing::class)?->connectionName);
    }
}

// ── Jobs ──────────────────────────────────────────────────────────────

class ParityJob extends Job
{
    protected int $tries = 1;

    public function __construct(public string $tag = '') {}

    public function handle(): void
    {
        ParityRecorder::$ran[] = $this->tag;
    }
}

class ParityFailingJob extends Job
{
    protected int $backoff = 0;

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
}

class SelfReleasingJob extends Job
{
    use InteractsWithQueue;

    public function handle(): void
    {
        $this->release(0);
    }
}

class SelfDeletingJob extends Job
{
    use InteractsWithQueue;

    public function handle(): void
    {
        $this->delete();
    }
}

class SelfFailingJob extends Job
{
    use InteractsWithQueue;

    public function handle(): void
    {
        $this->fail(new \RuntimeException('giving up'));
    }
}

class RetryUntilJob extends Job
{
    protected int $tries = 1;
    protected int $backoff = 0;

    public function retryUntil(): ?int
    {
        return time() + 3600;
    }

    public function handle(): void
    {
        throw new \RuntimeException('still failing');
    }
}

class ExpiredRetryUntilJob extends Job
{
    protected int $tries = 99;
    protected int $backoff = 0;

    public function retryUntil(): ?int
    {
        return time() - 1;
    }

    public function handle(): void
    {
        throw new \RuntimeException('too late');
    }
}

class MaxExceptionsJob extends Job
{
    protected int $tries = 20;
    protected int $backoff = 0;
    protected ?int $maxExceptions = 2;

    public function handle(): void
    {
        throw new \RuntimeException('throws every time');
    }
}

class BackoffListJob extends Job
{
    protected int $tries = 20;

    public function backoff(): int|array
    {
        return [1, 10, 60];
    }

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
}

// ── Doubles ───────────────────────────────────────────────────────────

class ParityRecorder
{
    /** @var array<int, string> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
    }
}

/** Counts failures without needing a database. */
class ParityFailedStore implements \Nitro\Queue\Contracts\FailedJobStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function log(QueuedJob $job, Throwable $e): string
    {
        $id = (string) count($this->rows);

        $this->rows[$id] = [
            'id' => $id,
            'queue' => $job->queue,
            'class' => $job->resolveName(),
            'attempts' => $job->attempts,
            'exception' => $e->getMessage(),
            'failed_at' => time(),
            'payload' => $job->payload,
        ];

        return $id;
    }

    public function all(int $limit = 50): array
    {
        return array_values($this->rows);
    }

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function forget(string $id): bool
    {
        if (! array_key_exists($id, $this->rows)) {
            return false;
        }

        unset($this->rows[$id]);

        return true;
    }

    public function clear(): int
    {
        $count = count($this->rows);
        $this->rows = [];

        return $count;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}

/**
 * Keeps every event, and can answer one.
 *
 * Both halves matter: the worker is expected to announce what it is
 * doing, and to let a listener answer Looping with false.
 */
class RecordingDispatcher implements EventDispatcher
{
    /** @var array<int, object> */
    public array $dispatched = [];

    /** @var array<class-string, mixed> */
    private array $answers = [];

    public function answer(string $event, mixed $answer): void
    {
        $this->answers[$event] = $answer;
    }

    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        if (is_object($event)) {
            $this->dispatched[] = $event;

            return $this->answers[$event::class] ?? null;
        }

        return null;
    }

    public function until(string|object $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /** @template T of object @param class-string<T> $type @return T|null */
    public function first(string $type): ?object
    {
        foreach ($this->dispatched as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }

    public function listen(string|array $events, callable|string $listener): void {}

    public function subscribe(string|object $subscriber): void {}

    public function hasListeners(string $event): bool
    {
        return false;
    }

    public function forget(string $event): void {}
}
