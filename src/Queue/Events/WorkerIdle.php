<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;

/**
 * A worker found no job to run.
 */
class WorkerIdle
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
        public ?WorkerOptions $workerOptions = null,
    ) {}
}
