<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;

/**
 * A worker is about to look for the next job.
 *
 * A listener returning false holds the worker back for one turn.
 */
class Looping
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
        public ?WorkerOptions $workerOptions = null,
    ) {}
}
