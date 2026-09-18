<?php

namespace Tests\Unit\Queue;

use Nitro\Queue\Drivers\RedisQueue;
use Nitro\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every Redis queue backing must show.
 *
 * Run twice: once against an in-memory double, which covers the driver's own
 * bookkeeping on any machine, and once against a real server, which is the only
 * thing that can confirm the Lua migration really is atomic.
 */
abstract class RedisQueueTestCase extends TestCase
{
    protected object $connection;
    protected RedisQueue $queue;

    /** The connection under test. */
    abstract protected function makeConnection(): object;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->makeConnection();
        $this->queue = $this->makeQueue();
    }

    protected function makeQueue(int $visibilityTimeout = 90): RedisQueue
    {
        return new RedisQueue($this->connection, 'test:queue:', $visibilityTimeout);
    }

    protected function job(string $payload = 'payload'): QueuedJob
    {
        return new QueuedJob(
            id: null,
            queue: 'default',
            payload: $payload,
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        );
    }

    // ─── Pushing and popping ──────────────────────────────

    public function test_a_pushed_job_comes_back(): void
    {
        $this->queue->push($this->job('payload-1'));

        $popped = $this->queue->pop();

        $this->assertNotNull($popped);
        $this->assertSame('payload-1', $popped->payload);
        $this->assertSame('default', $popped->queue);
    }

    public function test_an_empty_queue_pops_nothing(): void
    {
        $this->assertNull($this->queue->pop());
    }

    public function test_push_returns_the_id_it_assigned(): void
    {
        $id = $this->queue->push($this->job());

        $this->assertSame($id, $this->queue->pop()->id);
    }

    public function test_jobs_come_back_in_the_order_they_went_in(): void
    {
        $this->queue->push($this->job('first'));
        $this->queue->push($this->job('second'));

        $this->assertSame('first', $this->queue->pop()->payload);
        $this->assertSame('second', $this->queue->pop()->payload);
    }

    public function test_named_queues_are_separate(): void
    {
        $this->queue->push($this->job('for-mail'), 'mail');

        $this->assertNull($this->queue->pop('default'));
        $this->assertSame('for-mail', $this->queue->pop('mail')->payload);
    }

    public function test_popping_counts_the_attempt(): void
    {
        $this->queue->push($this->job());

        $this->assertSame(1, $this->queue->pop()->attempts);
    }

    /** A reserved job must not be handed to a second worker. */
    public function test_a_reserved_job_is_not_popped_again(): void
    {
        $this->queue->push($this->job());

        $this->assertNotNull($this->queue->pop());
        $this->assertNull($this->queue->pop());
    }

    // ─── Delays ───────────────────────────────────────────

    public function test_a_delayed_job_waits_for_its_time(): void
    {
        $this->queue->later(60, $this->job());

        $this->assertNull($this->queue->pop());
        $this->assertSame(1, $this->queue->size());
    }

    public function test_a_delay_that_has_elapsed_is_runnable(): void
    {
        $this->queue->later(0, $this->job());

        $this->assertNotNull($this->queue->pop());
    }

    // ─── Completing and retrying ──────────────────────────

    public function test_deleting_removes_every_trace(): void
    {
        $this->queue->push($this->job());

        $this->queue->delete($this->queue->pop());

        $this->assertSame(0, $this->queue->size());
        $this->assertNull($this->queue->pop());
    }

    public function test_releasing_makes_a_job_runnable_again(): void
    {
        $this->queue->push($this->job('retry-me'));

        $this->queue->release($this->queue->pop());

        $popped = $this->queue->pop();

        $this->assertNotNull($popped);
        $this->assertSame('retry-me', $popped->payload);
    }

    /** attempts is incremented by pop(), so a release must not lose the count. */
    public function test_a_released_job_keeps_its_attempt_count(): void
    {
        $this->queue->push($this->job());

        $this->queue->release($this->queue->pop());

        $this->assertSame(2, $this->queue->pop()->attempts);
    }

    public function test_releasing_with_a_delay_holds_the_job_back(): void
    {
        $this->queue->push($this->job());

        $this->queue->release($this->queue->pop(), 60);

        $this->assertNull($this->queue->pop());
        $this->assertSame(1, $this->queue->size());
    }

    public function test_size_counts_ready_delayed_and_reserved(): void
    {
        $this->queue->push($this->job());
        $this->queue->later(60, $this->job());
        $this->queue->push($this->job());

        $this->queue->pop();

        $this->assertSame(3, $this->queue->size());
    }

    /** A worker that dies leaves its hold to lapse rather than losing the job. */
    public function test_a_lapsed_reservation_returns_to_the_queue(): void
    {
        $queue = $this->makeQueue(0);

        $queue->push($this->job('orphaned'));
        $queue->pop();

        $this->assertSame('orphaned', $queue->pop()->payload);
    }

    /** An id whose envelope went missing must not stall the worker. */
    public function test_an_id_without_an_envelope_is_skipped(): void
    {
        $first = $this->queue->push($this->job('gone'));
        $this->queue->push($this->job('still-here'));

        $this->connection->hDel('test:queue:default:data', (string) $first);

        $this->assertSame('still-here', $this->queue->pop()->payload);
    }
}
