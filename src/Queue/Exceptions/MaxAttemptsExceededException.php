<?php

namespace Nitro\Queue\Exceptions;

use Nitro\Queue\QueuedJob;
use RuntimeException;

/**
 * Thrown when the Worker observes that a job has already hit its $tries
 * cap (typically because a prior worker crashed mid-handle and the row
 * reservation expired). The Worker catches this and routes the job to
 * the failed store rather than running handle() one more time on a
 * job the user already considers dead.
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
