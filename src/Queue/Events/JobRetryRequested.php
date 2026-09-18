<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A failed job was asked to run again.
 */
class JobRetryRequested
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
    ) {}
}
