<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job is about to be pushed onto a queue.
 */
class JobQueueing
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
    ) {}
}
