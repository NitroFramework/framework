<?php

namespace Nitro\Queue\Events;

/**
 * A worker process has started.
 */
class WorkerStarting
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
    ) {}
}
