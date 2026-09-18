<?php

namespace Nitro\Queue\Events;

/**
 * A worker process is exiting.
 */
class WorkerStopping
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
    ) {}
}
