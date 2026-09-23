<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;
use Nitro\Queue\WorkerStopReason;

/**
 * A worker process is exiting.
 */
class WorkerStopping
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
        public int $status = 0,
        public ?WorkerStopReason $reason = null,
        public ?WorkerOptions $workerOptions = null,
        public ?int $jobsProcessed = null,
        public int|float|null $lastJobProcessedAt = null,
        public int|float|null $memoryUsage = null,
    ) {}
}
