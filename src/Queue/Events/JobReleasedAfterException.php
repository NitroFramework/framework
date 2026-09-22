<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * A job threw and was put back on the queue to be tried again.
 *
 * Distinct from JobReleased, which also covers a job that released
 * itself deliberately: this one always carries the exception that
 * caused it.
 */
class JobReleasedAfterException
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public int $delay = 0,
        public ?Throwable $exception = null,
    ) {}
}
