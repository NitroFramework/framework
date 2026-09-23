<?php

namespace Nitro\Queue\Exceptions;

use Nitro\Queue\QueuedJob;

/**
 * Thrown when a job ran past the time it was allowed.
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
