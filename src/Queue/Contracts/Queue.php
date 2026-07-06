<?php

namespace Nitro\Queue\Contracts;

use Nitro\Queue\QueuedJob;

/**
 * Storage-side contract for a queue backend.
 *
 * Producers call push() / later(); the Worker calls pop(), then either
 * delete() on success or release() on a retryable failure. The contract
 * is intentionally tiny — a few rows in a table plus a way to reserve
 * one atomically is enough to satisfy it.
 *
 * Semantics:
 *   - At-least-once. pop() reserves a job (so two workers can't pull it
 *     concurrently) but does NOT delete it. If the worker crashes after
 *     pop() but before delete(), the reservation eventually expires and
 *     another worker picks it up. Jobs MUST be idempotent.
 *   - FIFO within a queue, modulo delays. A job with a future available_at
 *     is invisible to pop() until its time comes.
 *   - Multiple named queues per backend ('default', 'mail', 'reports', …).
 */
interface Queue
{
    /**
     * Push a job for immediate processing on the named queue.
     *
     * @return int|string  The new job's primary key (driver-defined type).
     */
    public function push(QueuedJob $job, string $queue = 'default'): int|string;

    /**
     * Push a job that won't become eligible to run until $delay seconds
     * from now. Used by retry-with-backoff and by ->delay(N) at dispatch.
     */
    public function later(int $delay, QueuedJob $job, string $queue = 'default'): int|string;

    /**
     * Reserve and return the next runnable job on the named queue, or
     * null if there is none. Reservation must be atomic across workers
     * — two pop()s from two processes on the same queue must never
     * return the same job.
     */
    public function pop(string $queue = 'default'): ?QueuedJob;

    /**
     * Delete a job from the queue. Called by the Worker after handle()
     * succeeds, and after a failed job has been recorded in the failed
     * store (so it doesn't get retried by reservation expiry).
     */
    public function delete(QueuedJob $job): void;

    /**
     * Release a previously-popped job back to the queue, optionally
     * delayed. Called by the Worker on a retryable failure. The job's
     * attempts counter increments; subsequent pop() may or may not pick
     * it up depending on whether $delay has elapsed.
     */
    public function release(QueuedJob $job, int $delay = 0): void;

    /**
     * Approximate count of jobs currently on the queue. Useful for
     * dashboards and health checks. Drivers MAY return the total
     * including reserved jobs — exact accounting is not required.
     */
    public function size(string $queue = 'default'): int;
}
