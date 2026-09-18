<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A worker is about to reserve the next job.
 */
class JobPopping
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
    ) {}
}
