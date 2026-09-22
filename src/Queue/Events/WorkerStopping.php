<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\WorkerOptions;
use Nitro\Queue\WorkerStopReason;

/**
 * A worker process is exiting.
 *
 * A supervisor restarts a worker whatever the exit code, so the code
 * alone says little; the reason is what separates a deliberate
 * --max-jobs recycle from a job that froze and had to be killed.
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
