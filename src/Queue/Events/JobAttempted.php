<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * An attempt at a job finished, successfully or not.
 *
 * JobProcessed and JobFailed each cover one outcome; this fires for
 * both, which is what a listener that has to clean up after every
 * attempt — resetting a connection, flushing a buffer — needs.
 */
class JobAttempted
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public ?Throwable $exception = null,
    ) {}

    /** Whether the attempt ran to completion. */
    public function successful(): bool
    {
        return $this->exception === null;
    }
}
