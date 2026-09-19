<?php

namespace Nitro\Concurrency\Drivers;

use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\TaskInvoker;
use RuntimeException;
use Throwable;

/**
 * Runs each task in a forked child of the current process.
 *
 * The child inherits the already-booted application, so unlike the process
 * driver there is no second bootstrap to pay for — the win grows with the
 * number of tasks. Results come back over a socket pair as serialized data.
 *
 * PREMISE (know it before you reach for it):
 *   - Needs ext-pcntl and ext-sockets, so it does not exist on Windows.
 *     Ask {@see supported()} rather than assuming.
 *   - A Closure IS accepted: the child is a copy of this process, so nothing
 *     has to cross a serialisation boundary on the way in. Only the RESULT is
 *     serialized, so that must still be serialisable.
 *   - The child shares every open handle at the moment of the fork. A database
 *     connection used on both sides will corrupt the protocol; open a fresh one
 *     inside the task if it needs the database.
 *   - Not safe inside a threaded SAPI. Under a worker server, fork from the
 *     request, never from the server's own loop.
 */
class ForkDriver implements Driver
{
    /** Whether this platform can fork at all. */
    public static function supported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('socket_create_pair');
    }

    /**
     * Fork one child per task and collect their results.
     *
     * @param  array<int|string, mixed> $tasks
     * @param  int|null                 $timeout Seconds to wait for all children.
     * @return array<int|string, mixed>
     */
    public function run(array $tasks, ?int $timeout = null): array
    {
        if ($tasks === []) {
            return [];
        }

        $this->assertSupported();

        $children = [];

        foreach ($tasks as $key => $task) {
            $pair = [];

            if (! socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
                throw new RuntimeException("Failed to open a socket pair for task [{$key}].");
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException("Failed to fork a child for task [{$key}].");
            }

            if ($pid === 0) {
                $this->runChild($task, $pair);
            }

            socket_close($pair[0]);
            $children[$key] = ['pid' => $pid, 'socket' => $pair[1]];
        }

        return $this->collect($children, $timeout);
    }

    /**
     * Fork each task and leave it running.
     *
     * The parent reaps nothing, so a finished child stays a zombie until this
     * process exits. Acceptable for a request that is about to end; a
     * long-lived worker should prefer the process driver.
     */
    public function defer(array $tasks): void
    {
        $this->assertSupported();

        foreach ($tasks as $task) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    TaskInvoker::invoke($task);
                } catch (Throwable) {
                    /* Nobody is listening; the parent has moved on. */
                }

                exit(0);
            }
        }
    }

    /**
     * The child half: run the task, post the outcome back, and leave.
     *
     * exit() rather than return, so the child never unwinds into the parent's
     * remaining work — it would finish the request a second time.
     */
    private function runChild(mixed $task, array $pair): never
    {
        socket_close($pair[1]);

        try {
            $payload = ['ok' => true, 'value' => TaskInvoker::invoke($task)];
        } catch (Throwable $exception) {
            $payload = ['ok' => false, 'error' => $exception->getMessage()];
        }

        $encoded = serialize($payload);
        @socket_write($pair[0], $encoded, strlen($encoded));
        socket_close($pair[0]);

        exit(0);
    }

    /**
     * Read every child's result, then reap it.
     *
     * @param  array<int|string, array{pid: int, socket: \Socket}> $children
     * @return array<int|string, mixed>
     */
    private function collect(array $children, ?int $timeout): array
    {
        $results = [];
        $deadline = $timeout === null ? null : time() + $timeout;

        foreach ($children as $key => $child) {
            if ($deadline !== null) {
                $remaining = max(1, $deadline - time());
                @socket_set_option($child['socket'], SOL_SOCKET, SO_RCVTIMEO, ['sec' => $remaining, 'usec' => 0]);
            }

            $raw = '';

            while (($chunk = @socket_read($child['socket'], 8192, PHP_BINARY_READ)) !== false && $chunk !== '') {
                $raw .= $chunk;
            }

            socket_close($child['socket']);
            pcntl_waitpid($child['pid'], $status);

            $results[$key] = $this->decode($raw, $key);
        }

        return $results;
    }

    /** Turn a child's reply into its value, or rethrow what it caught. */
    private function decode(string $raw, int|string $key): mixed
    {
        if ($raw === '') {
            throw new RuntimeException("Task [{$key}] returned nothing — the child died before replying.");
        }

        $payload = @unserialize($raw);

        if (! is_array($payload) || ! array_key_exists('ok', $payload)) {
            throw new RuntimeException("Task [{$key}] returned something unreadable.");
        }

        if ($payload['ok'] === false) {
            throw new RuntimeException("Task [{$key}] failed: " . ($payload['error'] ?? 'unknown error'));
        }

        return $payload['value'];
    }

    /** @throws RuntimeException When the platform cannot fork. */
    private function assertSupported(): void
    {
        if (! static::supported()) {
            throw new RuntimeException(
                'The fork driver needs ext-pcntl and ext-sockets, which this platform does not have. '
                . 'Use the process driver, or the sync driver in tests.'
            );
        }
    }
}
