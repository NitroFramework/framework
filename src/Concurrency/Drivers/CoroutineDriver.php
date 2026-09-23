<?php

namespace Nitro\Concurrency\Drivers;

use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\TaskInvoker;
use RuntimeException;
use Throwable;

/**
 * Runs each task in a Swoole coroutine, inside this process.
 *
 * The cheapest of the drivers by a wide margin: a coroutine is a stack and a
 * context, where a fork is a copy of the process and the process driver is a
 * second framework boot. Ten tasks cost ten stacks rather than ten processes.
 *
 * PREMISE (know it before you reach for it):
 *   - Needs ext-swoole, which does not build on Windows. Ask {@see supported()}
 *     rather than assuming; a platform without it should use fork or process.
 *   - A Closure IS accepted. Nothing crosses a process boundary, so neither the
 *     task nor its result has to serialise.
 *   - Concurrency comes from yielding at I/O, so a task that only computes runs
 *     to completion before the next one starts. Fan out waiting, not work.
 *   - Yielding requires Swoole's runtime hooks, which the server enables. What
 *     yields is I/O made through PHP's own functions — PDO, curl, streams,
 *     sleep(). An extension that opens its own sockets does not.
 *   - Every task shares this process. A task that mutates a singleton is racing
 *     the others, in a way the fork and process drivers hid by giving each task
 *     its own copy of memory.
 */
class CoroutineDriver implements Driver
{
    /** Whether this platform can run coroutines at all. */
    public static function supported(): bool
    {
        return extension_loaded('swoole')
            && function_exists('Swoole\Coroutine\run')
            && class_exists(\Swoole\Coroutine\WaitGroup::class);
    }

    /**
     * Whether a coroutine is already running.
     *
     * Nested scheduling is not allowed: Coroutine\run() starts a scheduler, and
     * starting one inside another is an error rather than a nesting. Inside a
     * coroutine the tasks are spawned directly instead.
     */
    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Run every task at once and collect what each returned.
     *
     * @param  array<int|string, mixed> $tasks
     * @param  int|null                 $timeout Seconds to wait for all of them.
     * @return array<int|string, mixed>
     */
    public function run(array $tasks, ?int $timeout = null): array
    {
        if ($tasks === []) {
            return [];
        }

        $this->assertSupported();

        $results = [];
        $failures = [];

        $spawn = function () use ($tasks, $timeout, &$results, &$failures): void {
            $group = new \Swoole\Coroutine\WaitGroup();

            foreach ($tasks as $key => $task) {
                $group->add();

                \Swoole\Coroutine::create(function () use ($key, $task, $group, &$results, &$failures): void {
                    try {
                        $results[$key] = TaskInvoker::invoke($task);
                    } catch (Throwable $exception) {
                        $failures[$key] = $exception;
                    } finally {
                        // In finally so a task that throws still releases the
                        // group; without it one failure hangs every caller
                        // until the timeout, and forever when there is none.
                        $group->done();
                    }
                });
            }

            // wait() takes seconds as a float, and -1 means no limit.
            if (! $group->wait($timeout ?? -1)) {
                throw new RuntimeException(
                    'Concurrent tasks did not all finish within ' . $timeout . ' seconds.'
                );
            }
        };

        static::inCoroutine() ? $spawn() : \Swoole\Coroutine\run($spawn);

        if ($failures !== []) {
            $key = array_key_first($failures);

            throw new RuntimeException(
                "Task [{$key}] failed: " . $failures[$key]->getMessage(),
                0,
                $failures[$key],
            );
        }

        // Keyed and ordered as they were given, which a coroutine finishing
        // early would otherwise decide for us.
        return array_replace(array_fill_keys(array_keys($tasks), null), $results);
    }

    /**
     * Start the tasks and return without waiting.
     *
     * Only meaningful inside a running scheduler: the coroutines live as long
     * as it does, so a caller outside one has nowhere to leave them and the
     * work is done now instead.
     *
     * @param array<int|string, mixed> $tasks
     */
    public function defer(array $tasks): void
    {
        $this->assertSupported();

        if (! static::inCoroutine()) {
            (new SyncDriver())->defer($tasks);

            return;
        }

        foreach ($tasks as $task) {
            \Swoole\Coroutine::create(static function () use ($task): void {
                try {
                    TaskInvoker::invoke($task);
                } catch (Throwable) {
                    /* Nobody is waiting; the caller has moved on. */
                }
            });
        }
    }

    /** @throws RuntimeException When the platform has no coroutines. */
    private function assertSupported(): void
    {
        if (! static::supported()) {
            throw new RuntimeException(
                'The coroutine driver needs ext-swoole, which this platform does not have. '
                . 'Use the fork driver, the process driver, or the sync driver in tests.'
            );
        }
    }
}
