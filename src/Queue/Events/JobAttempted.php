<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * An attempt at a job finished, successfully or not.
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
