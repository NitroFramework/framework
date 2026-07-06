<?php

namespace Tests\Unit\Queue;

use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

class ArrayQueueTest extends TestCase
{
    public function test_pushed_job_can_be_popped(): void
    {
        $queue = new ArrayQueue();
        $envelope = $this->envelope(new ArrayQueueTestJob('a'));

        $queue->push($envelope);
        $popped = $queue->pop();

        $this->assertNotNull($popped);
        $this->assertSame($envelope, $popped, 'pop returns the same envelope');
        $this->assertSame(1, $popped->attempts, 'pop increments attempts');
        $this->assertNotNull($popped->reservedAt, 'pop marks reserved_at');
    }

    public function test_pop_is_fifo_within_a_queue(): void
    {
        $queue = new ArrayQueue();
        $queue->push($this->envelope(new ArrayQueueTestJob('first')));
        $queue->push($this->envelope(new ArrayQueueTestJob('second')));

        ['instance' => $a] = $queue->pop()->decode();
        ['instance' => $b] = $queue->pop()->decode();

        $this->assertSame('first', $a->tag);
        $this->assertSame('second', $b->tag);
    }

    public function test_named_queues_are_isolated(): void
    {
        $queue = new ArrayQueue();
        $queue->push($this->envelope(new ArrayQueueTestJob('mail')), 'mail');
        $queue->push($this->envelope(new ArrayQueueTestJob('default')), 'default');

        $popped = $queue->pop('mail');
        $this->assertNotNull($popped);
        ['instance' => $job] = $popped->decode();
        $this->assertSame('mail', $job->tag);

        // The default-queue job is still there
        $this->assertSame(1, $queue->size('default'));
    }

    public function test_reserved_jobs_are_invisible_to_subsequent_pops(): void
    {
        $queue = new ArrayQueue();
        $queue->push($this->envelope(new ArrayQueueTestJob('only')));

        $first = $queue->pop();
        $second = $queue->pop();

        $this->assertNotNull($first);
        $this->assertNull($second,
            'a reserved-but-not-deleted job must not be re-popped');
    }

    public function test_delete_removes_the_job(): void
    {
        $queue = new ArrayQueue();
        $queue->push($this->envelope(new ArrayQueueTestJob('a')));

        $job = $queue->pop();
        $queue->delete($job);

        $this->assertSame(0, $queue->size());
    }

    public function test_release_makes_the_job_pop_able_again(): void
    {
        $queue = new ArrayQueue();
        $queue->push($this->envelope(new ArrayQueueTestJob('a')));

        $first = $queue->pop();
        $queue->release($first, 0);
        $second = $queue->pop();

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
    }

    public function test_delay_hides_a_job_until_its_time_comes(): void
    {
        $queue = new ArrayQueue();
        $envelope = $this->envelope(new ArrayQueueTestJob('later'));

        $queue->later(60, $envelope);

        $this->assertNull($queue->pop(),
            'a job with available_at > now must not be popped');
        $this->assertSame(1, $queue->size(),
            'but it still counts toward size()');
    }

    private function envelope(Job $job): QueuedJob
    {
        return new QueuedJob(
            id: null,
            queue: 'default',
            payload: QueuedJob::encode($job),
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        );
    }
}

class ArrayQueueTestJob extends Job
{
    public function __construct(public string $tag) {}
    public function handle(): void {}
}
