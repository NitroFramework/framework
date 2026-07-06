<?php

namespace Nitro\Thrust;

/**
 * Task helpers for worker mode, mirroring Laravel Octane's Octane::concurrently.
 *
 * Octane fans tasks out to Swoole task workers; Nitro's FrankenPHP path has no
 * coroutine runtime, so tasks run sequentially in-process (the same fallback
 * Octane uses when no concurrent runtime is available). The API is identical,
 * so app code written against concurrently() keeps working — it just doesn't
 * parallelise on this runtime.
 */
class Thrust
{
    /**
     * Run a set of callables and return their results in the same key order.
     *
     * @param  array<int|string, callable>  $tasks
     * @return array<int|string, mixed>
     */
    public static function concurrently(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }

        return $results;
    }
}
