<?php

namespace Nitro\Queue\Exceptions;

use Nitro\Queue\QueuedJob;
use RuntimeException;

/**
 * Thrown when a job has no attempts left.
 */
class MaxAttemptsExceededException extends RuntimeException
{
    /** The envelope that ran out of attempts, when one is known. */
    public ?QueuedJob $job = null;

    /** @param string $class The job's class name, when the payload decoded. */
    public static function forJob(QueuedJob $job, string $class = ''): static
    {
        $exception = new static(
            ($class !== '' ? $class : ($job->id ?? 'job'))
            . ' has been attempted too many times.'
        );

        $exception->job = $job;

        return $exception;
    }
}
