<?php

namespace Nitro\Queue;

/**
 * Adds the fluent dispatch entry point to a Job.
 *
 *   SendWelcomeEmail::dispatch($userId);
 *   SendWelcomeEmail::dispatch($userId)->onQueue('mail');
 *   SendWelcomeEmail::dispatch($userId)->delay(60);
 *   SendWelcomeEmail::dispatch($userId)->onConnection('redis')->onQueue('mail')->delay(120);
 *
 * dispatch() constructs the job with whatever args you pass and returns
 * a PendingDispatch — a tiny builder that captures connection / queue /
 * delay overrides, then commits to the QueueManager on destruct (or on
 * the first method call that requires resolution).
 *
 * The "commit on destruct" trick is what makes the bare-call form
 * (SendWelcomeEmail::dispatch($id);) work — the PendingDispatch falls
 * out of scope at end-of-statement and pushes the job in __destruct.
 * That's the same shape Laravel uses; it surprises nobody.
 */
trait Dispatchable
{
    /**
     * Construct a job with the given args and return a builder to
     * customise its queue/connection/delay before push.
     */
    public static function dispatch(mixed ...$args): PendingDispatch
    {
        return new PendingDispatch(new static(...$args));
    }

    /**
     * Synchronous dispatch — execute immediately in the current process,
     * bypassing the queue entirely. Useful in tests that need the side
     * effects but don't want to spin a worker.
     */
    public static function dispatchSync(mixed ...$args): void
    {
        $job = new static(...$args);
        \app(QueueManager::class)
            ->connection('sync')
            ->push(new QueuedJob(
                id: null,
                queue: $job->queueName(),
                payload: QueuedJob::encode($job),
                attempts: 0,
                availableAt: time(),
                reservedAt: null,
                createdAt: time(),
            ));
    }
}
