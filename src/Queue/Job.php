<?php

namespace Nitro\Queue;

/**
 * Base class for all background jobs.
 *
 *   class SendWelcomeEmail extends Job {
 *       public function __construct(public int $userId) {}
 *
 *       public function handle(Mailer $mailer): void {
 *           $user = User::find($this->userId);
 *           $mailer->send(new WelcomeMail($user));
 *       }
 *   }
 *
 * Dispatching:
 *   SendWelcomeEmail::dispatch($user->id);
 *   SendWelcomeEmail::dispatch($user->id)->onQueue('mail');
 *   SendWelcomeEmail::dispatch($user->id)->delay(60);
 *
 * The handle() method is resolved by the container at run time — type-hint
 * any dependency you need and the container injects it. Constructor args
 * are serialized into the payload, so keep them small and primitives-only
 * (ids, not models — re-query inside handle() to avoid stale objects).
 *
 * Subclass knobs (override as needed):
 *   protected int    $tries       = 3;     // max attempts before failure
 *   protected int    $backoff     = 5;     // seconds between retries
 *   protected int    $timeout     = 60;    // seconds one attempt may run
 *   protected string $queueName   = '...'; // route to a specific queue
 *   protected ?string $onConnection = null; // override default connection
 *
 * Tries vs attempts: a job that fails 3 times with $tries=3 lands in the
 * failed store on the third attempt. $backoff is consulted after each
 * failure; override backoff() (instance method) for exponential or
 * jittered retry intervals.
 */
abstract class Job
{
    use Dispatchable;
    use Attributes\ReadsQueueAttributes;

    /** Maximum number of attempts before this job is considered failed. */
    protected int $tries = 3;

    /**
     * Seconds between attempts when handle() throws.
     *
     * A per-attempt schedule goes through backoff() rather than here: a
     * subclass may not widen this type, so making the property itself
     * accept an array would break every job that declares `protected
     * int $backoff`.
     */
    protected int $backoff = 5;

    /**
     * Seconds one attempt may run before the worker kills the process.
     *
     * Null defers to the worker's --timeout. The guard needs pcntl, so
     * on a build without it this is not enforced.
     */
    protected ?int $timeout = null;

    /**
     * Whether a job that hits its timeout is failed outright.
     *
     * The default is to let it be retried, on the reading that a
     * timeout is usually a slow dependency rather than a broken job.
     */
    protected bool $failOnTimeout = false;

    /**
     * Exceptions to tolerate before failing, independent of attempts.
     *
     * A job that releases itself repeatedly can attempt many times
     * without ever throwing; this caps the throws rather than the
     * attempts. Null leaves it to $tries alone.
     */
    protected ?int $maxExceptions = null;

    /**
     * When to stop retrying, as a timestamp or a date.
     *
     * A time budget rather than a count: a job worth retrying for an
     * hour should not stop after three quick failures, and one that has
     * been failing for an hour is not going to succeed on attempt four.
     * Set, this replaces $tries entirely.
     */
    protected \DateTimeInterface|int|null $retryUntil = null;

    /** Named queue this job runs on. Override at the class level or via ->onQueue(). */
    protected string $queueName = 'default';

    /** Optional connection override. Null = default from config. */
    protected ?string $onConnection = null;

    /**
     * The attempt number currently being processed (1-based). The Worker sets
     * this before running the job so handle()/backoff() can be attempt-aware
     * (e.g. exponential backoff: `min(60, 2 ** $this->currentAttempts)`).
     */
    protected int $currentAttempts = 0;

    /** The Worker calls this with the reserved attempt count before running the job. */
    public function setCurrentAttempts(int $attempts): void
    {
        $this->currentAttempts = $attempts;
    }

    /** The attempt number currently being processed (1-based, 0 before it runs). */
    public function attempts(): int
    {
        return $this->currentAttempts;
    }

    /**
     * The job's main entry point. The Worker resolves it via the container
     * so you can type-hint any dependency.
     *
     *   public function handle(Mailer $mailer, Logger $log): void { … }
     */
    abstract public function handle(): void;

    /**
     * Per-job hook the Worker calls after the job has been declared
     * permanently failed (attempts >= $tries). Override to send a
     * notification, write an audit row, etc. Default is a no-op.
     */
    public function failed(\Throwable $exception): void
    {
        // intentionally empty
    }

    /**
     * Seconds to wait before retrying after a failed attempt. Override
     * for exponential backoff, jitter, or attempt-aware schedules:
     *
     *   public function backoff(): int {
     *       return min(60, 2 ** $this->currentAttempts);
     *   }
     *
     * Return an array to give each attempt its own wait; the last entry
     * covers every attempt past the end of it.
     *
     * @return int|array<int, int>
     */
    public function backoff(): int|array
    {
        /** @var int|array<int, int> */
        return $this->queueAttribute(Attributes\Backoff::class, 'backoff', 0);
    }

    public function tries(): int
    {
        return (int) $this->queueAttribute(Attributes\Tries::class, 'tries', 1);
    }

    /** Seconds this job may run, or null to take the worker's limit. */
    public function timeout(): ?int
    {
        $timeout = $this->queueAttribute(Attributes\Timeout::class, 'timeout');

        return $timeout === null ? null : (int) $timeout;
    }

    /** Whether hitting the timeout fails the job rather than retrying it. */
    public function shouldFailOnTimeout(): bool
    {
        return (bool) $this->queueAttribute(Attributes\FailOnTimeout::class, 'failOnTimeout', false);
    }

    /** Throws to tolerate before failing, or null to go by attempts alone. */
    public function maxExceptions(): ?int
    {
        $max = $this->queueAttribute(Attributes\MaxExceptions::class, 'maxExceptions');

        return $max === null ? null : (int) $max;
    }

    /**
     * The timestamp past which this job is no longer retried.
     *
     * Null means the attempt count decides instead.
     */
    public function retryUntil(): ?int
    {
        return $this->retryUntil instanceof \DateTimeInterface
            ? $this->retryUntil->getTimestamp()
            : $this->retryUntil;
    }

    public function queueName(): string
    {
        return (string) $this->queueAttribute(Attributes\Queue::class, 'queueName', 'default');
    }

    public function connectionName(): ?string
    {
        $connection = $this->queueAttribute(Attributes\Connection::class, 'onConnection');

        return $connection === null ? null : (string) $connection;
    }
}
