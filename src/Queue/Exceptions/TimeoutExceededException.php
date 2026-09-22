<?php

namespace Nitro\Queue\Exceptions;

use Nitro\Queue\QueuedJob;

/**
 * A job ran past its timeout and the worker was killed out from under it.
 *
 * Raised from the SIGALRM handler, so it does not travel back through
 * handle() — it exists so the failed-job record says the job froze
 * rather than leaving an empty exception behind.
 */
class TimeoutExceededException extends MaxAttemptsExceededException
{
    public static function forJob(QueuedJob $job, string $class = ''): static
    {
        $exception = new static(
            ($class !== '' ? $class : ($job->id ?? 'job')) . ' has timed out.'
        );

        $exception->job = $job;

        return $exception;
    }
}
