<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Queue\Drivers\SyncQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

class SyncQueueTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        SyncQueueTestRecorder::$ran = [];
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function test_push_runs_the_job_immediately(): void
    {
        $queue = new SyncQueue(Container::getInstance());
        $job = new SyncQueueTestJob('hello');

        $queue->push(new QueuedJob(
            id: null,
            queue: 'default',
            payload: QueuedJob::encode($job),
            attempts: 0,
            availableAt: time(),
            reservedAt: null,
            createdAt: time(),
        ));

        $this->assertSame(['hello'], SyncQueueTestRecorder::$ran,
            'SyncQueue::push() should run the job inline');
    }

    public function test_pop_always_returns_null(): void
    {
        $queue = new SyncQueue(Container::getInstance());
        $this->assertNull($queue->pop(),
            'sync driver has no persistent store — pop is always empty');
    }

    public function test_release_re_executes_for_retry_path_coverage(): void
    {
        $queue = new SyncQueue(Container::getInstance());
        $envelope = new QueuedJob(
            id: '1',
            queue: 'default',
            payload: QueuedJob::encode(new SyncQueueTestJob('retried')),
            attempts: 1,
            availableAt: time(),
            reservedAt: time(),
            createdAt: time(),
        );

        $queue->release($envelope, 0);
        $this->assertSame(['retried'], SyncQueueTestRecorder::$ran);
        $this->assertSame(2, $envelope->attempts,
            'release should bump the attempts counter');
    }
}

class SyncQueueTestJob extends Job
{
    protected int $tries = 1;

    public function __construct(public string $tag) {}

    public function handle(): void
    {
        SyncQueueTestRecorder::$ran[] = $this->tag;
    }
}

class SyncQueueTestRecorder
{
    /** @var array<int, string> */
    public static array $ran = [];
}
