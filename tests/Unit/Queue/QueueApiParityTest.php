<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Queue\Attributes\Backoff;
use Nitro\Queue\Attributes\Connection;
use Nitro\Queue\Attributes\Delay;
use Nitro\Queue\Attributes\FailOnTimeout;
use Nitro\Queue\Attributes\MaxExceptions;
use Nitro\Queue\Attributes\Queue as OnQueue;
use Nitro\Queue\Attributes\Timeout;
use Nitro\Queue\Attributes\Tries;
use Nitro\Queue\CallQueuedClosure;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/** The job's own terms, and the queue contract around it. */
class QueueApiParityTest extends TestCase
{
    protected function setUp(): void
    {
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    // ── Attributes ────────────────────────────────────────────────────

    public function test_an_attribute_sets_what_a_property_would(): void
    {
        $job = new AttributedJob();

        $this->assertSame(7, $job->tries());
        $this->assertSame(90, $job->timeout());
        $this->assertSame(4, $job->maxExceptions());
        $this->assertSame('reports', $job->queueName());
        $this->assertSame('redis', $job->connectionName());
    }

    /** An attribute with no value says its one thing by being present. */
    public function test_a_valueless_attribute_means_true(): void
    {
        $this->assertTrue((new AttributedJob())->shouldFailOnTimeout());
        $this->assertFalse((new PlainJob())->shouldFailOnTimeout());
    }

    public function test_an_attribute_carries_a_backoff_schedule(): void
    {
        $this->assertSame([1, 10, 60], (new AttributedJob())->backoff());
    }

    /** An attribute wins over a property's declared default. */
    public function test_an_attribute_wins_over_a_declared_default(): void
    {
        $this->assertSame(5, (new OverridingJob())->tries());
    }

    /** A value assigned at run time wins over both. */
    public function test_a_value_set_at_run_time_wins_over_an_attribute(): void
    {
        $job = new OverridingJob();
        $job->setTries(9);

        $this->assertSame(9, $job->tries());
    }

    /** Without either, the property's declared default stands. */
    public function test_the_declared_default_is_the_last_word(): void
    {
        $job = new PlainJob();

        $this->assertSame(3, $job->tries());
        $this->assertNull($job->timeout());
        $this->assertNull($job->maxExceptions());
        $this->assertSame('default', $job->queueName());
        $this->assertNull($job->connectionName());
    }

    /** Resolved terms must not ride along in the payload. */
    public function test_reading_a_jobs_terms_does_not_grow_its_payload(): void
    {
        $bare = strlen(QueuedJob::encode(new AttributedJob()));

        $read = new AttributedJob();
        $read->tries();
        $read->backoff();
        $read->queueName();

        $this->assertSame($bare, strlen(QueuedJob::encode($read)));
    }

    // ── Envelope identity ─────────────────────────────────────────────

    /** Each queued job carries an identifier of its own. */
    public function test_an_envelope_carries_a_stable_identifier(): void
    {
        $first = new QueuedJob(null, 'default', QueuedJob::encode(new PlainJob()), 0, time(), null, time());
        $second = new QueuedJob(null, 'default', QueuedJob::encode(new PlainJob()), 0, time(), null, time());

        $this->assertNotNull($first->uuid());
        $this->assertNotSame($first->uuid(), $second->uuid());
        $this->assertSame($first->uuid(), $first->uuid(), 'and it does not change on each read');
    }

    public function test_an_envelope_names_its_job_without_deserializing_it(): void
    {
        $envelope = new QueuedJob(null, 'default', QueuedJob::encode(new PlainJob()), 0, time(), null, time());

        $this->assertSame(PlainJob::class, $envelope->resolveName());
    }

    /** A payload from before identifiers existed still decodes. */
    public function test_an_older_payload_has_no_identifier_rather_than_failing(): void
    {
        $envelope = new QueuedJob(
            null,
            'default',
            json_encode(['class' => PlainJob::class, 'data' => serialize(new PlainJob())]),
            0,
            time(),
            null,
            time(),
        );

        $this->assertNull($envelope->uuid());
        $this->assertInstanceOf(PlainJob::class, $envelope->decode()['instance']);
    }

    // ── The queue contract ────────────────────────────────────────────

    public function test_a_queue_can_be_named_first(): void
    {
        $queue = new ArrayQueue();

        $queue->pushOn('mail', $this->envelope());

        $this->assertSame(1, $queue->size('mail'));
        $this->assertSame(0, $queue->size('default'));
    }

    public function test_a_delayed_push_can_name_its_queue_first(): void
    {
        $queue = new ArrayQueue();

        $queue->laterOn('mail', 60, $this->envelope());

        $this->assertSame(1, $queue->size('mail'));
        $this->assertNull($queue->pop('mail'), 'not yet eligible');
    }

    /** Many jobs go on in one call. */
    public function test_many_jobs_are_pushed_at_once(): void
    {
        $queue = new ArrayQueue();

        $queue->bulk([$this->envelope(), $this->envelope(), $this->envelope()], 'imports');

        $this->assertSame(3, $queue->size('imports'));
    }

    public function test_a_connection_remembers_the_name_it_was_given(): void
    {
        $queue = (new ArrayQueue())->setConnectionName('secondary');

        $this->assertSame('secondary', $queue->getConnectionName());
    }

    // ── Queued closures ───────────────────────────────────────────────

    /** Work with no arguments and one call site needs no class. */
    public function test_a_closure_can_be_queued_and_run(): void
    {
        $this->container()->instance(CallableInvoker::class, new DirectInvoker());

        ClosureRecorder::$ran = [];

        $job = CallQueuedClosure::create(static function (): void {
            ClosureRecorder::$ran[] = 'ran';
        });

        $decoded = $this->roundTrip($job);
        $decoded->handle();

        $this->assertSame(['ran'], ClosureRecorder::$ran, 'the closure survived the queue');
    }

    /** What the closure captured travels with it. */
    public function test_a_queued_closure_keeps_what_it_captured(): void
    {
        $this->container()->instance(CallableInvoker::class, new DirectInvoker());

        ClosureRecorder::$ran = [];
        $tag = 'captured';

        $job = CallQueuedClosure::create(static function () use ($tag): void {
            ClosureRecorder::$ran[] = $tag;
        });

        $this->roundTrip($job)->handle();

        $this->assertSame(['captured'], ClosureRecorder::$ran);
    }

    public function test_a_failed_closure_runs_its_failure_callbacks(): void
    {
        ClosureRecorder::$ran = [];

        $job = CallQueuedClosure::create(static fn () => null)
            ->onFailure(static function (\Throwable $e): void {
                ClosureRecorder::$ran[] = $e->getMessage();
            });

        $this->roundTrip($job)->failed(new \RuntimeException('it broke'));

        $this->assertSame(['it broke'], ClosureRecorder::$ran);
    }

    /** A closure has no class name, so it is named by where it was written. */
    public function test_a_queued_closure_says_where_it_came_from(): void
    {
        $job = CallQueuedClosure::create(static fn () => null);

        $this->assertStringContainsString('QueueApiParityTest.php', $job->displayName());

        $this->assertStringStartsWith('nightly - Closure', $job->name('nightly')->displayName());
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function container(): Container
    {
        return Container::getInstance();
    }

    private function envelope(): QueuedJob
    {
        return new QueuedJob(null, 'default', QueuedJob::encode(new PlainJob()), 0, time(), null, time());
    }

    /** Push a job through encode/decode, as the queue would. */
    private function roundTrip(Job $job): Job
    {
        $envelope = new QueuedJob(null, 'default', QueuedJob::encode($job), 0, time(), null, time());

        return $envelope->decode()['instance'];
    }
}

// ── Jobs ──────────────────────────────────────────────────────────────

class PlainJob extends Job
{
    public function handle(): void {}
}

#[Tries(7)]
#[Timeout(90)]
#[MaxExceptions(4)]
#[FailOnTimeout]
#[Backoff(1, 10, 60)]
#[OnQueue('reports')]
#[Connection('redis')]
#[Delay(30)]
class AttributedJob extends Job
{
    public function handle(): void {}
}

#[Tries(5)]
class OverridingJob extends Job
{
    protected int $tries = 2;

    public function setTries(int $tries): void
    {
        $this->tries = $tries;
    }

    public function handle(): void {}
}

// ── Doubles ───────────────────────────────────────────────────────────

class ClosureRecorder
{
    /** @var array<int, string> */
    public static array $ran = [];
}

/** Calls the closure without resolving anything. */
class DirectInvoker implements CallableInvoker
{
    public function call(callable|string|array $callable, array $parameters = []): mixed
    {
        return $callable();
    }

    public function arguments(object|string $object, string $method, array $parameters = []): array
    {
        return [];
    }

    public function bindParametersUsing(\Closure $resolver): void {}
}
