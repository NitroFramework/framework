<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Queue\Batchable;
use Nitro\Queue\Batching\Batch;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchFactory;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Batching\DatabaseBatchRepository;
use Nitro\Queue\Batching\PendingBatch;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

/**
 * Job batches: dispatching, counters, callbacks, cancellation.
 */
class BatchTest extends TestCase
{
    private QueueManager $queues;
    private DatabaseBatchRepository $repository;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        BatchSpy::$ran = [];

        $connection = new class(['driver' => 'sqlite', 'database' => ':memory:']) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(\PDO $pdo): void
            {
            }
        };

        $reflection = new \ReflectionClass(DB::class);

        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue(null, $connection);

        $grammar = $reflection->getProperty('grammar');
        $grammar->setAccessible(true);
        $grammar->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $connection->statement(
            'CREATE TABLE job_batches (
                id TEXT PRIMARY KEY,
                name TEXT,
                total_jobs INTEGER,
                pending_jobs INTEGER,
                failed_jobs INTEGER,
                failed_job_ids TEXT,
                options TEXT,
                created_at INTEGER,
                cancelled_at INTEGER NULL,
                finished_at INTEGER NULL
            )'
        );

        Container::reset();
        $this->container = Container::getInstance();

        $config = new class implements \Nitro\Foundation\Contracts\ConfigRepository {
            /** @var array<string, mixed> */
            private array $values = [
                'queue.default' => 'sync',
                'queue.batching.table' => 'job_batches',
            ];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function all(): array
            {
                return [];
            }
        };

        $this->queues = new QueueManager($this->container, $config);
        $this->queues->extend('sync', new ArrayQueue());

        $this->repository = new DatabaseBatchRepository(new BatchFactory($this->queues));

        $this->container->instance(BatchRepository::class, $this->repository);
        $this->container->instance(BatchFactory::class, new BatchFactory($this->queues));
        $this->container->instance(QueueManager::class, $this->queues);
    }

    protected function tearDown(): void
    {
        Container::reset();
        DB::disconnect();
        parent::tearDown();
    }

    private function pending(array $jobs): PendingBatch
    {
        return new PendingBatch($this->container, $this->queues, $this->repository, $jobs);
    }

    // ─── Dispatching ──────────────────────────────────────

    public function test_dispatch_records_the_batch_and_its_size(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2)])
            ->name('import')
            ->dispatch();

        $this->assertSame('import', $batch->name);
        $this->assertSame(2, $batch->totalJobs);
        $this->assertSame(2, $batch->pendingJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertFalse($batch->finished());
        $this->assertFalse($batch->cancelled());
    }

    public function test_dispatched_jobs_carry_the_batch_id(): void
    {
        $jobs = [new BatchedJob(1), new BatchedJob(2)];

        $batch = $this->pending($jobs)->dispatch();

        foreach ($jobs as $job) {
            $this->assertSame($batch->id, $job->batchId);
            $this->assertTrue($job->batching());
        }
    }

    public function test_a_job_without_the_trait_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batchable');

        $this->pending([new UnbatchableJob()]);
    }

    public function test_dispatch_if_and_unless(): void
    {
        $this->assertNull($this->pending([new BatchedJob(1)])->dispatchIf(false));
        $this->assertNull($this->pending([new BatchedJob(1)])->dispatchUnless(true));

        $this->assertInstanceOf(Batch::class, $this->pending([new BatchedJob(1)])->dispatchIf(true));
        $this->assertInstanceOf(Batch::class, $this->pending([new BatchedJob(1)])->dispatchUnless(false));
    }

    // ─── Progress ─────────────────────────────────────────

    public function test_counters_and_progress_move_as_jobs_settle(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2), new BatchedJob(3), new BatchedJob(4)])
            ->dispatch();

        $this->assertSame(0, $batch->progress());

        $batch->recordSuccessfulJob('job-1');
        $batch = $batch->fresh();

        $this->assertSame(3, $batch->pendingJobs);
        $this->assertSame(1, $batch->processedJobs());
        $this->assertSame(25, $batch->progress());
        $this->assertFalse($batch->finished());
    }

    public function test_the_batch_finishes_when_the_last_job_settles(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2)])->dispatch();

        $batch->recordSuccessfulJob('job-1');
        $batch->fresh()->recordSuccessfulJob('job-2');

        $batch = $batch->fresh();

        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(100, $batch->progress());
        $this->assertTrue($batch->finished());
    }

    public function test_progress_of_an_empty_batch_is_zero(): void
    {
        $this->assertSame(0, $this->pending([])->dispatch()->progress());
    }

    // ─── Failures ─────────────────────────────────────────

    public function test_a_failed_job_is_counted_and_recorded(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2)])->dispatch();

        $batch->recordFailedJob('job-1', new \RuntimeException('boom'));

        $batch = $batch->fresh();

        $this->assertSame(1, $batch->failedJobs);
        $this->assertSame(1, $batch->pendingJobs);
        $this->assertTrue($batch->hasFailures());
        $this->assertSame(['job-1'], $batch->failedJobIds);
    }

    public function test_a_failure_cancels_the_batch_by_default(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2)])->dispatch();

        $this->assertFalse($batch->allowsFailures());

        $batch->recordFailedJob('job-1', new \RuntimeException('boom'));

        $this->assertTrue($batch->fresh()->cancelled());
    }

    public function test_allow_failures_lets_the_rest_continue(): void
    {
        $batch = $this->pending([new BatchedJob(1), new BatchedJob(2)])
            ->allowFailures()
            ->dispatch();

        $this->assertTrue($batch->allowsFailures());

        $batch->recordFailedJob('job-1', new \RuntimeException('boom'));

        $this->assertFalse($batch->fresh()->cancelled());
    }

    // ─── Callbacks ────────────────────────────────────────

    public function test_then_runs_only_when_nothing_failed(): void
    {
        $batch = $this->pending([new BatchedJob(1)])
            ->then(BatchSpy::class)
            ->finally([BatchSpy::class, 'done'])
            ->dispatch();

        $batch->recordSuccessfulJob('job-1');

        (new BatchCallbacks($this->container))->settled($batch->fresh());

        $this->assertSame(['invoked', 'done'], BatchSpy::$ran);
    }

    public function test_then_is_skipped_when_a_job_failed(): void
    {
        $batch = $this->pending([new BatchedJob(1)])
            ->allowFailures()
            ->then(BatchSpy::class)
            ->finally([BatchSpy::class, 'done'])
            ->dispatch();

        $batch->recordFailedJob('job-1', new \RuntimeException('boom'));

        (new BatchCallbacks($this->container))->settled($batch->fresh());

        $this->assertSame(['done'], BatchSpy::$ran);
    }

    public function test_catch_runs_for_a_failure(): void
    {
        $batch = $this->pending([new BatchedJob(1)])
            ->catch([BatchSpy::class, 'caught'])
            ->dispatch();

        (new BatchCallbacks($this->container))->failed($batch, new \RuntimeException('boom'));

        $this->assertSame(['caught: boom'], BatchSpy::$ran);
    }

    public function test_a_closure_callback_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closure cannot be stored');

        $this->pending([new BatchedJob(1)])->then(fn () => null);
    }

    public function test_callback_getters_report_what_was_registered(): void
    {
        $pending = $this->pending([new BatchedJob(1)])
            ->before(BatchSpy::class)
            ->progress(BatchSpy::class)
            ->then(BatchSpy::class)
            ->catch(BatchSpy::class)
            ->finally(BatchSpy::class);

        $this->assertSame([BatchSpy::class], $pending->beforeCallbacks());
        $this->assertSame([BatchSpy::class], $pending->progressCallbacks());
        $this->assertSame([BatchSpy::class], $pending->thenCallbacks());
        $this->assertSame([BatchSpy::class], $pending->catchCallbacks());
        $this->assertSame([BatchSpy::class], $pending->finallyCallbacks());
        $this->assertSame([BatchSpy::class], $pending->failureCallbacks());
    }

    public function test_a_dispatched_batch_reports_its_callbacks(): void
    {
        $batch = $this->pending([new BatchedJob(1)])
            ->then(BatchSpy::class)
            ->catch(BatchSpy::class)
            ->finally(BatchSpy::class)
            ->progress(BatchSpy::class)
            ->dispatch();

        $this->assertTrue($batch->hasThenCallbacks());
        $this->assertTrue($batch->hasCatchCallbacks());
        $this->assertTrue($batch->hasFailureCallbacks());
        $this->assertTrue($batch->hasFinallyCallbacks());
        $this->assertTrue($batch->hasProgressCallbacks());
    }

    // ─── Managing a running batch ─────────────────────────

    public function test_add_grows_a_running_batch(): void
    {
        $batch = $this->pending([new BatchedJob(1)])->dispatch();

        $batch = $batch->add([new BatchedJob(2), new BatchedJob(3)]);

        $this->assertSame(3, $batch->totalJobs);
        $this->assertSame(3, $batch->pendingJobs);
    }

    public function test_cancel_and_delete(): void
    {
        $batch = $this->pending([new BatchedJob(1)])->dispatch();

        $batch->cancel();

        $this->assertTrue($batch->fresh()->cancelled());
        $this->assertTrue($batch->fresh()->canceled());

        $batch->delete();

        $this->assertNull($this->repository->find($batch->id));
    }

    public function test_options_are_carried_onto_the_batch(): void
    {
        $batch = $this->pending([new BatchedJob(1)])
            ->onQueue('imports')
            ->onConnection('sync')
            ->withOption('tenant', 42)
            ->dispatch();

        $this->assertSame('imports', $batch->options['queue']);
        $this->assertSame('sync', $batch->options['connection']);
        $this->assertSame(42, $batch->options['tenant']);
    }

    public function test_to_array_and_json(): void
    {
        $batch = $this->pending([new BatchedJob(1)])->name('nightly')->dispatch();

        $array = $batch->toArray();

        $this->assertSame('nightly', $array['name']);
        $this->assertSame(1, $array['totalJobs']);
        $this->assertSame(0, $array['progress']);
        $this->assertSame($array, $batch->jsonSerialize());
        $this->assertIsString(json_encode($batch));
    }

    // ─── Repository ───────────────────────────────────────

    public function test_get_lists_recent_batches(): void
    {
        $this->pending([new BatchedJob(1)])->name('one')->dispatch();
        $this->pending([new BatchedJob(2)])->name('two')->dispatch();

        $this->assertCount(2, $this->repository->get());
    }

    public function test_prune_removes_finished_batches(): void
    {
        $batch = $this->pending([new BatchedJob(1)])->dispatch();
        $batch->recordSuccessfulJob('job-1');

        $this->assertTrue($batch->fresh()->finished());

        $pruned = $this->repository->prune(new \DateTimeImmutable('+1 hour'));

        $this->assertSame(1, $pruned);
        $this->assertNull($this->repository->find($batch->id));
    }

    // ─── Batchable on the job ─────────────────────────────

    public function test_a_job_reads_its_own_batch(): void
    {
        $job = new BatchedJob(1);

        $this->assertFalse($job->batching());
        $this->assertNull($job->batch());

        $batch = $this->pending([$job])->dispatch();

        $this->assertSame($batch->id, $job->batch()?->id);
    }

    public function test_a_fake_batch_needs_no_storage(): void
    {
        $job = (new BatchedJob(1))->withFakeBatch('fake-id', 'fake', 10, 4, 1);

        $this->assertTrue($job->batching());
        $this->assertSame('fake-id', $job->batch()->id);
        $this->assertSame(10, $job->batch()->totalJobs);
        $this->assertSame(60, $job->batch()->progress());
    }
}

class BatchedJob extends Job
{
    use Batchable;

    public function __construct(public int $number = 0)
    {
    }

    public function handle(): void
    {
    }
}

class UnbatchableJob extends Job
{
    public function handle(): void
    {
    }
}

class BatchSpy
{
    /** @var array<int, string> */
    public static array $ran = [];

    public function __invoke(Batch $batch): void
    {
        self::$ran[] = 'invoked';
    }

    public function done(Batch $batch): void
    {
        self::$ran[] = 'done';
    }

    public function caught(Batch $batch, \Throwable $exception): void
    {
        self::$ran[] = 'caught: ' . $exception->getMessage();
    }
}
