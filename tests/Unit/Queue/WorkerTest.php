<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Foundation\Config;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use Nitro\Queue\QueueManager;
use Nitro\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * End-to-end Worker tests using ArrayQueue + a tiny in-memory
 * FailedJobStore. Verifies the four important paths:
 *   - success → delete from queue
 *   - retryable failure → release with backoff, eventually fail
 *   - poison pill (attempts > tries) → fail without running handle()
 *   - --once mode exits after one pop
 */
class WorkerTest extends TestCase
{
    private Container $container;
    private QueueManager $queues;
    private ArrayQueue $array;
    private InMemoryFailedStore $failed;
    private Worker $worker;

    protected function setUp(): void
    {
        Container::reset();
        $this->container = Container::getInstance();
        WorkerTestRecorder::reset();

        // Minimal Config so QueueManager doesn't blow up if someone
        // calls connection(null) and we never set a default.
        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => ['array' => ['driver' => 'array']],
            ],
        ]);
        $this->container->instance(Config::class, $config);

        $this->queues = new QueueManager($this->container, $config);
        $this->array  = new ArrayQueue();
        $this->queues->extend('array', $this->array);

        $this->failed = new InMemoryFailedStore();
        $this->worker = new Worker(
            $this->queues,
            $this->failed,
            $this->container,
            null, // no cache => no restart-signal path
        );
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function test_successful_job_runs_and_gets_deleted(): void
    {
        $this->push(new WorkerSuccessJob('hello'));

        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame(['hello'], WorkerTestRecorder::$ran);
        $this->assertSame(0, $this->array->size(), 'successful job removed from queue');
        $this->assertSame(0, $this->failed->count(), 'no failure recorded');
    }

    public function test_retryable_failure_releases_and_eventually_fails(): void
    {
        $this->push(new WorkerAlwaysFailingJob());

        // Job has $tries=3, $backoff=0. Five --once runs is enough to
        // exhaust the budget (we make backoff zero so we don't need to
        // wait between calls).
        for ($i = 0; $i < 5; $i++) {
            $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);
        }

        $this->assertSame(0, $this->array->size(),
            'queue should be empty after the job lands in failed');
        $this->assertSame(1, $this->failed->count(),
            'one failed entry recorded');
        $this->assertGreaterThanOrEqual(1, WorkerAlwaysFailingJob::$failedCalls,
            'job\'s failed() hook ran');
    }

    public function test_poison_pill_skips_handle_and_fails_immediately(): void
    {
        // Pre-bumped attempts simulate a prior worker that crashed after
        // bumping the counter but before delete()/release().
        $job = new WorkerSuccessJob('would-not-run');
        $envelope = new QueuedJob(
            id: null,
            queue: 'default',
            payload: QueuedJob::encode($job),
            attempts: 5,        // already over $tries=1
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        );
        $this->array->push($envelope);

        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame([], WorkerTestRecorder::$ran,
            'handle() must not run on a poison pill');
        $this->assertSame(1, $this->failed->count());
    }

    public function test_once_mode_exits_on_empty_queue(): void
    {
        $exit = $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);
        $this->assertSame(0, $exit, '--once with empty queue returns 0 immediately');
    }

    public function test_tries_zero_means_unlimited_and_releases_instead_of_failing(): void
    {
        $this->push(new WorkerAlwaysFailingJob());

        // tries=0 = no cap. A failing job must be released for retry, NOT failed
        // on the first pop — the old guard (attempts > 0) failed everything.
        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0, 'tries' => 0]);

        $this->assertSame(0, $this->failed->count(), 'tries=0 must not fail the job');
        $this->assertSame(1, $this->array->size(), 'job released back onto the queue');
    }

    public function test_undecodable_payload_fails_immediately(): void
    {
        $this->array->push(new QueuedJob(
            id: null,
            queue: 'default',
            payload: 'this-is-not-a-serialized-job',
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        ));

        // Even with a generous retry budget, a job whose class can't be decoded
        // can never succeed — it must go straight to failed, not be retried.
        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0, 'tries' => 5]);

        $this->assertSame(1, $this->failed->count(), 'corrupt payload failed immediately');
        $this->assertSame(0, $this->array->size(), 'not released for retry');
    }

    private function push(Job $job): void
    {
        $this->array->push(new QueuedJob(
            id: null,
            queue: 'default',
            payload: QueuedJob::encode($job),
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        ));
    }
}

class WorkerSuccessJob extends Job
{
    protected int $tries = 1;
    public function __construct(public string $tag) {}
    public function handle(): void
    {
        WorkerTestRecorder::$ran[] = $this->tag;
    }
}

class WorkerAlwaysFailingJob extends Job
{
    public static int $failedCalls = 0;
    protected int $tries = 3;
    protected int $backoff = 0;
    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
    public function failed(Throwable $e): void
    {
        self::$failedCalls++;
    }
}

class WorkerTestRecorder
{
    /** @var array<int, string> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
        WorkerAlwaysFailingJob::$failedCalls = 0;
    }
}

class InMemoryFailedStore implements FailedJobStore
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function log(QueuedJob $job, Throwable $e): string
    {
        $id = (string) count($this->rows);
        $this->rows[$id] = [
            'id'        => $id,
            'queue'     => $job->queue,
            'class'     => 'test',
            'attempts'  => $job->attempts,
            'exception' => $e->getMessage(),
            'failed_at' => time(),
            'payload'   => $job->payload,
        ];
        return $id;
    }
    public function all(int $limit = 50): array { return array_values($this->rows); }
    public function find(string $id): ?array     { return $this->rows[$id] ?? null; }
    public function forget(string $id): bool
    {
        if (!array_key_exists($id, $this->rows)) {
            return false;
        }
        unset($this->rows[$id]);
        return true;
    }
    public function clear(): int                  { $n = count($this->rows); $this->rows = []; return $n; }
    public function count(): int                  { return count($this->rows); }
}
