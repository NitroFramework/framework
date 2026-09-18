<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job has been pushed onto a queue.
 */
class JobQueued
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
    ) {}
}
