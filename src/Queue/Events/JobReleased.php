<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job was put back on the queue.
 */
class JobReleased
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public int $delay = 0,
    ) {}
}
