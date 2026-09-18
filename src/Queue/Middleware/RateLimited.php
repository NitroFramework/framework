<?php

namespace Nitro\Queue\Middleware;

use Nitro\Cache\RateLimiter;
use Throwable;

/**
 * Limits how often jobs sharing a key may run.
 *
 *     public function middleware(): array
 *     {
 *         return [(new RateLimited('reports'))->allow(10)->every(60)];
 *     }
 *
 * A job over the limit is released back to the queue until the window
 * reopens, or dropped when dontRelease() was asked for.
 */
class RateLimited
{
    protected int $maxAttempts = 60;

    protected int $decaySeconds = 60;

    /** Seconds to wait before retrying, or null to drop the job. */
    protected ?int $releaseAfter = 0;

    public function __construct(
        protected string $key = 'default',
    ) {}

    /** Jobs permitted per window. */
    public function allow(int $maxAttempts): static
    {
        $this->maxAttempts = max(1, $maxAttempts);

        return $this;
    }

    /** Length of the window in seconds. */
    public function every(int $seconds): static
    {
        $this->decaySeconds = max(1, $seconds);

        return $this;
    }

    /**
     * Wait for the window to reopen rather than using a fixed delay.
     *
     * Releasing after a guessed delay puts the job back before the limiter
     * will take it, which burns an attempt for nothing.
     */
    public function releaseAfterWindow(): static
    {
        $this->releaseAfter = -1;

        return $this;
    }

    public function releaseAfter(int $seconds): static
    {
        $this->releaseAfter = $seconds;

        return $this;
    }

    /** Drop the job instead of queueing it again. */
    public function dontRelease(): static
    {
        $this->releaseAfter = null;

        return $this;
    }

    public function handle(mixed $job, callable $next): mixed
    {
        $limiter = $this->limiter();

        if ($limiter === null) {
            return $next($job);
        }

        $key = 'queue-limit:' . $this->key;

        if ($limiter->tooManyAttempts($key, $this->maxAttempts)) {
            return $this->blocked($job, $limiter->availableIn($key));
        }

        $limiter->hit($key, $this->decaySeconds);

        return $next($job);
    }

    private function blocked(mixed $job, int $availableIn): mixed
    {
        if ($this->releaseAfter === null || ! method_exists($job, 'release')) {
            return null;
        }

        $job->release($this->releaseAfter === -1 ? max(1, $availableIn) : $this->releaseAfter);

        return null;
    }

    private function limiter(): ?RateLimiter
    {
        try {
            return app(RateLimiter::class);
        } catch (Throwable) {
            return null;
        }
    }
}
