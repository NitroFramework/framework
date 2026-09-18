<?php

namespace Nitro\Queue\Middleware;

use Closure;

/**
 * Drops a job without running it when a condition holds.
 *
 *     public function middleware(): array
 *     {
 *         return [Skip::when($this->tenant->suspended)];
 *     }
 */
class Skip
{
    public function __construct(
        protected bool $skip = false,
    ) {}

    /** Skip the job when the condition is truthy. */
    public static function when(Closure|bool $condition): static
    {
        return new static($condition instanceof Closure ? (bool) $condition() : $condition);
    }

    /** Skip the job unless the condition is truthy. */
    public static function unless(Closure|bool $condition): static
    {
        return new static($condition instanceof Closure ? ! $condition() : ! $condition);
    }

    public function handle(mixed $job, callable $next): mixed
    {
        return $this->skip ? null : $next($job);
    }
}