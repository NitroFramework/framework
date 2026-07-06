<?php

namespace Nitro\Queue\Drivers;

use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\QueuedJob;

/**
 * In-memory queue. State lives for the lifetime of this instance only —
 * a brand-new ArrayQueue is empty even within the same request unless
 * you reuse the instance. Built for tests:
 *
 *   $queue = new ArrayQueue();
 *   SendWelcomeEmail::dispatch($id);  // queued, not run
 *   $job = $queue->pop();
 *   $this->assertNotNull($job);
 *
 * Honors delay (later()), reservation, release-with-delay, and FIFO-
 * within-queue ordering. Multiple named queues are supported. Use this
 * driver to test code that DEPENDS on queue semantics — for code that
 * only cares that the job ran at all, use SyncQueue.
 */
class ArrayQueue implements Queue
{
    /** @var array<string, list<QueuedJob>> Queue name → ordered job list. */
    private array $queues = [];

    private int $nextId = 1;

    public function push(QueuedJob $job, string $queue = 'default'): int|string
    {
        $job->id = (string) $this->nextId++;
        $job->queue = $queue;
        $this->queues[$queue][] = $job;
        return $job->id;
    }

    public function later(int $delay, QueuedJob $job, string $queue = 'default'): int|string
    {
        $job->availableAt = time() + max(0, $delay);
        return $this->push($job, $queue);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        if (empty($this->queues[$queue])) {
            return null;
        }

        $now = time();
        foreach ($this->queues[$queue] as $i => $candidate) {
            if ($candidate->reservedAt !== null) {
                continue; // already taken
            }
            if ($candidate->availableAt > $now) {
                continue; // not yet eligible (delayed / backoff)
            }
            $candidate->reservedAt = $now;
            $candidate->attempts++;
            return $candidate;
        }
        return null;
    }

    public function delete(QueuedJob $job): void
    {
        $list = $this->queues[$job->queue] ?? [];
        foreach ($list as $i => $row) {
            if ($row->id === $job->id) {
                array_splice($this->queues[$job->queue], $i, 1);
                return;
            }
        }
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        // Find the same row, clear reservation, push availability forward.
        // We don't increment attempts here — that happened in pop().
        $list = $this->queues[$job->queue] ?? [];
        foreach ($list as $row) {
            if ($row->id === $job->id) {
                $row->reservedAt = null;
                $row->availableAt = time() + max(0, $delay);
                return;
            }
        }
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->queues[$queue] ?? []);
    }
}
