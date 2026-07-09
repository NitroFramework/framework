<?php

namespace Nitro\Concurrency\Drivers;

use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\TaskInvoker;

/**
 * Runs tasks one after another in the current process. No parallelism — it's the
 * safe fallback and the driver you want under test (deterministic, no subprocess,
 * accepts raw Closures). `run()` returns the same shape as the real drivers so a
 * suite can swap to it without changing call sites.
 */
class SyncDriver implements Driver
{
    public function run(array $tasks, ?int $timeout = null): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = TaskInvoker::invoke($task);
        }

        return $results;
    }
}
