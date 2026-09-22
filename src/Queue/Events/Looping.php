<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;

/**
 * A worker is about to look for the next job.
 *
 * A listener that returns false from this holds the worker back for one
 * turn of the loop, which is how work is kept off a queue during a
 * migration or a deploy without stopping the process.
 */
class Looping
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
        public ?WorkerOptions $workerOptions = null,
    ) {}
}
