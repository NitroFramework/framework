<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job ran without throwing.
 */
class JobProcessed
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
    ) {}
}
