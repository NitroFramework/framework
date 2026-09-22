<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;

/**
 * A worker process has started.
 */
class WorkerStarting
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
        public ?WorkerOptions $workerOptions = null,
    ) {}
}
