<?php

namespace Nitro\Queue\Middleware;

/**
 * Drops a batched job when its batch has been cancelled.
 *
 * A cancelled batch stops dispatching new work, but jobs already queued still
 * reach a worker; this is what stops them running.
 */
class SkipIfBatchCancelled
{
    public function handle(mixed $job, callable $next): mixed
    {
        if (method_exists($job, 'batch') && $job->batch()?->cancelled()) {
            return null;
        }

        return $next($job);
    }
}