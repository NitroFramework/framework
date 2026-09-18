<?php

namespace Nitro\Queue\Events;

/**
 * A worker found no job to run.
 */
class WorkerIdle
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
    ) {}
}
