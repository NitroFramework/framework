<?php

namespace Nitro\Queue;

/**
 * Everything `queue:work` was told on the command line.
 */
class WorkerOptions
{
    /**
     * @param string    $name             Names this worker, for popUsing().
     * @param int|array<int, int>|string $backoff Seconds between attempts, or one
     *        value per attempt as an array or comma-separated list.
     * @param int       $memory           Megabytes the process may consume.
     * @param int       $timeout          Seconds a single job may run.
     * @param int|float $sleep            Seconds to wait when the queue is empty.
     * @param ?int      $maxTries         Attempts before a job is failed; 0 is
     *        no limit, and null leaves the decision to each job's own $tries.
     * @param bool      $force            Run even while the application is down.
     * @param bool      $stopWhenEmpty    Exit as soon as nothing is queued.
     * @param int       $maxJobs          Exit after this many jobs; 0 is no limit.
     * @param int       $maxTime          Exit after this many seconds; 0 is no limit.
     * @param int|float $rest             Seconds to pause between jobs.
     * @param int       $stopWhenEmptyFor Exit once the queue has been empty this long.
     */
    public function __construct(
        public string $name = 'default',
        public int|array|string $backoff = 0,
        public int $memory = 128,
        public int $timeout = 60,
        public int|float $sleep = 3,
        public ?int $maxTries = null,
        public bool $force = false,
        public bool $stopWhenEmpty = false,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int|float $rest = 0,
        public int $stopWhenEmptyFor = 0,
    ) {}
}
