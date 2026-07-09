<?php

namespace Nitro\Concurrency\Drivers;

use Nitro\Concurrency\Console\ConcurrencyInvokeCommand;
use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\TaskInvoker;
use Nitro\Container\Container;

/**
 * Runs each task in its OWN php subprocess (`php nitro concurrency:invoke`), all
 * launched at once via proc_open, so they execute in real OS-level parallelism.
 * The task spec is passed as a base64 argument; the subprocess resolves it,
 * runs it, and prints the serialized return value, which we collect here.
 *
 * PREMISE (know it before you reach for it):
 *   - Tasks must be SERIALISABLE — an invokable class-string or [class, method, args],
 *     NOT a Closure (closures can't cross a process boundary; use the sync/http path).
 *   - Return values must be serialisable (they round-trip through serialize()).
 *   - Each subprocess boots the framework fresh, so there is NO shared memory and a
 *     per-task bootstrap cost. Only a win for genuinely SLOW tasks; for parallel
 *     HTTP, prefer Concurrency::http() (curl_multi, in-process, no boot).
 *
 * This is per-request task fan-out, not coroutines — see the Driver contract.
 */
class ProcessDriver implements Driver
{
    public function run(array $tasks, ?int $timeout = null): array
    {
        if ($tasks === []) {
            return [];
        }

        // Validate every task up front (before touching the container/filesystem) so
        // a Closure fails fast with a clear message rather than mid-spawn.
        foreach ($tasks as $task) {
            if (! TaskInvoker::isSerializable($task)) {
                throw new \InvalidArgumentException(
                    'The process driver cannot run a Closure — it must cross a process boundary. '
                    . 'Pass an invokable class-string or [class, method, args], or use Concurrency::http() / the sync driver for closures.'
                );
            }
        }

        $paths   = Container::getInstance()->get('paths');
        $console = $paths->base('nitro');
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $procs = [];
        foreach ($tasks as $key => $task) {
            $payload = base64_encode(serialize($task));
            $command = [PHP_BINARY, $console, 'concurrency:invoke', $payload];

            $pipes = [];
            $proc = @proc_open($command, $descriptor, $pipes, $paths->base(), null);
            if (! is_resource($proc)) {
                throw new \RuntimeException("Failed to spawn a concurrency subprocess for task [{$key}].");
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$key] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => ''];
        }

        $this->drain($procs, $timeout);

        $results = [];
        foreach ($procs as $key => $p) {
            $decoded = $this->extractResult($p['stdout']);

            if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
                throw new \RuntimeException(
                    "Concurrency task [{$key}] returned no valid result. stderr: " . trim($p['stderr'])
                    . ' stdout: ' . trim($p['stdout'])
                );
            }
            if ($decoded['ok'] !== true) {
                throw new \RuntimeException("Concurrency task [{$key}] failed: " . ($decoded['error'] ?? 'unknown error'));
            }

            $results[$key] = unserialize(base64_decode($decoded['result']));
        }

        return $results;
    }

    /**
     * Pull the sentinel-wrapped result out of a subprocess's stdout. The command
     * prints OPEN . base64(json) . CLOSE, so anything else the console emitted
     * (banners, warnings) is ignored.
     *
     * @return array<string, mixed>|null
     */
    private function extractResult(string $stdout): ?array
    {
        $open  = ConcurrencyInvokeCommand::OPEN;
        $close = ConcurrencyInvokeCommand::CLOSE;

        $start = strpos($stdout, $open);
        $end   = strrpos($stdout, $close);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $start  += strlen($open);
        $encoded = substr($stdout, $start, $end - $start);
        $json    = base64_decode($encoded, true);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Poll every subprocess until all exit (or the timeout trips), collecting output. */
    private function drain(array &$procs, ?int $timeout): void
    {
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        do {
            $running = 0;

            foreach ($procs as &$p) {
                if ($p['out'] === null) {
                    continue; // already finished
                }

                $p['stdout'] .= (string) fread($p['out'], 8192);
                $p['stderr'] .= (string) fread($p['err'], 8192);

                $status = proc_get_status($p['proc']);
                if ($status['running']) {
                    $running++;
                    continue;
                }

                // Finished — drain any tail, then close.
                $p['stdout'] .= (string) stream_get_contents($p['out']);
                $p['stderr'] .= (string) stream_get_contents($p['err']);
                fclose($p['out']);
                fclose($p['err']);
                proc_close($p['proc']);
                $p['out'] = null;
            }
            unset($p);

            if ($running > 0) {
                if ($deadline !== null && microtime(true) > $deadline) {
                    $this->terminateAll($procs);
                    throw new \RuntimeException("Concurrency tasks exceeded the {$timeout}s timeout.");
                }
                usleep(1000); // 1ms — plenty for a handful of tasks
            }
        } while ($running > 0);
    }

    private function terminateAll(array &$procs): void
    {
        foreach ($procs as &$p) {
            if ($p['out'] !== null) {
                @proc_terminate($p['proc']);
                @fclose($p['out']);
                @fclose($p['err']);
                @proc_close($p['proc']);
                $p['out'] = null;
            }
        }
        unset($p);
    }
}
