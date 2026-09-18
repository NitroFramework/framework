<?php

namespace Nitro\Queue\Batching;

/**
 * The counters a batch was left with after one job settled.
 */
class UpdatedBatchJobCounts
{
    public function __construct(
        public int $pendingJobs = 0,
        public int $failedJobs = 0,
    ) {}

    /** Whether this job was the last one outstanding. */
    public function allJobsHaveRanExactlyOnce(): bool
    {
        return ($this->pendingJobs - $this->failedJobs) === 0;
    }
}
