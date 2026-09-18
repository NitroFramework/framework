<?php

namespace Nitro\Queue;

use Nitro\Queue\Contracts\Queue;
use RuntimeException;
use Throwable;

/**
 * Lets a job act on its own place in the queue.
 *
 *     public function handle(): void
 *     {
 *         if ($this->attempts() > 3) {
 *             $this->fail(new GaveUp());
 *             return;
 *         }
 *
 *         if (! $this->ready()) {
 *             $this->release(30);
 *         }
 *     }
 *
 * The worker hands the envelope in before running the job.
 */
trait InteractsWithQueue
{
    protected ?QueuedJob $queuedJob = null;

    protected ?Queue $queueConnection = null;

    protected bool $jobDeleted = false;

    protected bool $jobReleased = false;

    protected ?int $jobReleaseDelay = null;

    protected ?Throwable $jobFailedWith = null;

    protected bool $jobFailed = false;

    /** Record interactions instead of performing them, for a test. */
    protected bool $fakesQueueInteractions = false;

    /** Give the job the envelope and connection it came from. */
    public function setJob(QueuedJob $job, ?Queue $queue = null): static
    {
        $this->queuedJob = $job;
        $this->queueConnection = $queue;

        return $this;
    }

    /** The attempt currently being processed. */
    public function attempts(): int
    {
        return $this->queuedJob?->attempts ?? $this->currentAttempts;
    }

    /** Remove the job from the queue without running it again. */
    public function delete(): void
    {
        $this->jobDeleted = true;

        if ($this->fakesQueueInteractions) {
            return;
        }

        if ($this->queuedJob !== null && $this->queueConnection !== null) {
            $this->queueConnection->delete($this->queuedJob);
        }
    }

    public function isDeleted(): bool
    {
        return $this->jobDeleted;
    }

    /** Put the job back on the queue, optionally after a delay. */
    public function release(int $delay = 0): void
    {
        $this->jobReleased = true;
        $this->jobReleaseDelay = $delay;

        if ($this->fakesQueueInteractions) {
            return;
        }

        if ($this->queuedJob !== null && $this->queueConnection !== null) {
            $this->queueConnection->release($this->queuedJob, $delay);
        }
    }

    public function isReleased(): bool
    {
        return $this->jobReleased;
    }

    /** Give up on the job now, regardless of attempts remaining. */
    public function fail(?Throwable $exception = null): void
    {
        $this->jobFailed = true;
        $this->jobFailedWith = $exception;

        if ($this->fakesQueueInteractions) {
            return;
        }

        $this->delete();

        $this->failed($exception ?? new RuntimeException('Job failed manually.'));
    }

    public function hasFailed(): bool
    {
        return $this->jobFailed;
    }

    /** Record what the job asks for rather than doing it. */
    public function withFakeQueueInteractions(): static
    {
        $this->fakesQueueInteractions = true;

        return $this;
    }

    /** @throws RuntimeException When the job was not deleted. */
    public function assertDeleted(): static
    {
        if (! $this->jobDeleted) {
            throw new RuntimeException('Expected the job to be deleted.');
        }

        return $this;
    }

    /** @throws RuntimeException When the job was deleted. */
    public function assertNotDeleted(): static
    {
        if ($this->jobDeleted) {
            throw new RuntimeException('Expected the job not to be deleted.');
        }

        return $this;
    }

    /** @throws RuntimeException When the job did not fail. */
    public function assertFailed(): static
    {
        if (! $this->jobFailed) {
            throw new RuntimeException('Expected the job to have failed.');
        }

        return $this;
    }

    /**
     * @param  Throwable|class-string $exception
     * @throws RuntimeException When the job failed with something else.
     */
    public function assertFailedWith(Throwable|string $exception): static
    {
        $this->assertFailed();

        $expected = is_string($exception) ? $exception : $exception::class;

        if (! $this->jobFailedWith instanceof $expected) {
            $actual = $this->jobFailedWith === null ? 'nothing' : $this->jobFailedWith::class;

            throw new RuntimeException("Expected the job to fail with {$expected}, got {$actual}.");
        }

        if ($exception instanceof Throwable
            && $this->jobFailedWith?->getMessage() !== $exception->getMessage()) {
            throw new RuntimeException(
                'Expected the job to fail with message [' . $exception->getMessage() . '].'
            );
        }

        return $this;
    }

    /** @throws RuntimeException When the job failed. */
    public function assertNotFailed(): static
    {
        if ($this->jobFailed) {
            throw new RuntimeException('Expected the job not to have failed.');
        }

        return $this;
    }

    /** @throws RuntimeException When the job was not released, or not with that delay. */
    public function assertReleased(?int $delay = null): static
    {
        if (! $this->jobReleased) {
            throw new RuntimeException('Expected the job to be released.');
        }

        if ($delay !== null && $this->jobReleaseDelay !== $delay) {
            throw new RuntimeException(
                "Expected the job to be released after {$delay}s, got {$this->jobReleaseDelay}s."
            );
        }

        return $this;
    }

    /** @throws RuntimeException When the job was released. */
    public function assertNotReleased(): static
    {
        if ($this->jobReleased) {
            throw new RuntimeException('Expected the job not to be released.');
        }

        return $this;
    }
}
