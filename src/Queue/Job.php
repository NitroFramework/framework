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

    /** Maximum number of attempts before this job is considered failed. */
    protected int $tries = 3;

    /** Default backoff (seconds) between attempts when handle() throws. */
    protected int $backoff = 5;

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
    public function failed(\Throwable $e): void
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
     */
    public function backoff(): int
    {
        return $this->backoff;
    }

    public function tries(): int
    {
        return $this->tries;
    }

    public function queueName(): string
    {
        return $this->queueName;
    }

    public function connectionName(): ?string
    {
        return $this->onConnection;
    }
}
