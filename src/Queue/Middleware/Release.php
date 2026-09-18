<?php

namespace Nitro\Queue\Middleware;

use Closure;

/**
 * Puts a job back on the queue when a condition holds.
 *
 *     return [Release::when($this->apiIsRateLimited())->for(60)];
 */
class Release
{
    protected int $delay = 0;

    public function __construct(
        protected bool $release = false,
    ) {}

    /** Release the job when the condition is truthy. */
    public static function when(Closure|bool $condition): static
    {
        return new static($condition instanceof Closure ? (bool) $condition() : $condition);
    }

    /** Release the job unless the condition is truthy. */
    public static function unless(Closure|bool $condition): static
    {
        return new static($condition instanceof Closure ? ! $condition() : ! $condition);
    }

    /** Seconds to wait before the job becomes runnable again. */
    public function for(int $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    public function handle(mixed $job, callable $next): mixed
    {
        if (! $this->release) {
            return $next($job);
        }

        if (method_exists($job, 'release')) {
            $job->release($this->delay);
        }

        return null;
    }
}