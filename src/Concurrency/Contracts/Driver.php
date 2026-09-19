<?php

namespace Nitro\Concurrency\Contracts;

/**
 * A driver that runs several independent tasks concurrently and returns their
 * results keyed the same as the input.
 *
 * NOTE ON SCOPE: this is PER-REQUEST TASK FAN-OUT, not coroutines. It parallelises
 * a handful of independent operations *inside one request* (e.g. "call 4 APIs and
 * run 2 reports at once") so wall-time is max(tasks) instead of sum(tasks). It does
 * NOT make the server handle more concurrent requests — that's a different concern
 * (coroutine-style request concurrency), which we intend to build as a separate
 * layer later.
 */
interface Driver
{
    /**
     * Run the given tasks concurrently; return [key => result] in the input order.
     *
     * @param  array<int|string, mixed>  $tasks
     * @param  int|null  $timeout  Per-task timeout in seconds (null = driver default).
     * @return array<int|string, mixed>
     */
    public function run(array $tasks, ?int $timeout = null): array;

    /**
     * Start the tasks and return without waiting for them.
     *
     * How the work is put aside is the driver's business — a detached process,
     * a fork, or simply doing it now when there is nowhere to put it.
     *
     * @param array<int|string, mixed> $tasks
     */
    public function defer(array $tasks): void;
}
