<?php

namespace Nitro\Coroutine;

use CurlHandle;
use CurlMultiHandle;
use Fiber;
use Nitro\Coroutine\Exceptions\DeadlockException;
use RuntimeException;

/**
 * The event loop — Nitro's replacement for Swoole's C-level coroutine scheduler,
 * built entirely on native PHP Fibers plus our own readiness poll. NO extensions.
 *
 * A run has three moving parts:
 *   - a READY queue of [coroutine, resumeValue] to run next,
 *   - TIMERS   (coroutines sleeping until a wall-clock time),
 *   - CURL waiters (coroutines parked on an in-flight HTTP transfer).
 *
 * loop(): drain the ready queue (running each fiber until it suspends/finishes);
 * when nothing is ready, poll() blocks on the nearest timer / curl activity, wakes
 * whatever became ready, and the cycle repeats until every coroutine has finished.
 *
 * SCOPE: one Scheduler drives ONE request's worth of coroutines and must fully
 * drain before the request returns — a Fiber outliving the request would leak in a
 * long-lived worker. This is intra-request concurrency, not request throughput.
 *
 * WHAT OVERLAPS: only I/O the loop can poll — HTTP via curl_multi, and timers.
 * A blocking PDO/curl_exec call inside a coroutine still blocks the whole loop;
 * there is no Swoole runtime hook to make it yield. Documented, not pretended away.
 */
final class Scheduler
{
    private static ?self $current = null;

    private int $nextId = 1;

    /** Live coroutines (spawned minus finished); the loop runs until this hits 0. */
    private int $live = 0;

    /** @var array<int, array{0: Coroutine, 1: mixed}> */
    private array $ready = [];

    /** @var array<int, array{until: float, co: Coroutine}> */
    private array $timers = [];

    /** @var array<int, array{co: Coroutine, handle: CurlHandle}> */
    private array $curlWaiters = [];

    private ?CurlMultiHandle $multi = null;

    private ?Coroutine $currentCoroutine = null;

    public static function current(): ?self
    {
        return self::$current;
    }

    public function currentCoroutine(): ?Coroutine
    {
        return $this->currentCoroutine;
    }

    /**
     * Run $main as the root coroutine, drive the loop to completion, and return its
     * value (or rethrow its exception). Restores any outer scheduler on the way out.
     */
    public function runRoot(callable $main): mixed
    {
        $previous = self::$current;
        self::$current = $this;

        try {
            $root = $this->spawn($main);
            $this->loop();

            if ($root->error !== null) {
                throw $root->error;
            }

            return $root->result;
        } finally {
            // Dropping the reference frees the multi handle. curl_multi_close() is a
            // deprecated no-op on PHP 8.0+ (and warns on 8.5+), so we don't call it.
            $this->multi = null;
            self::$current = $previous;
        }
    }

    /** Create a coroutine and queue it to run. Returns the handle for Co::await(). */
    public function spawn(callable $callable): Coroutine
    {
        $co = new Coroutine($this->nextId++, $callable);
        $this->live++;
        $this->ready[] = [$co, null];

        return $co;
    }

    /** Queue an already-parked coroutine to resume with a value. */
    public function schedule(Coroutine $co, mixed $value = null): void
    {
        $this->ready[] = [$co, $value];
    }

    // --- coroutine-side blocking primitives (these run INSIDE a fiber) --------------

    /** Suspend the running coroutine for $seconds of wall-clock time. */
    public function sleep(float $seconds): void
    {
        Fiber::suspend(['op' => 'sleep', 'until' => microtime(true) + max(0.0, $seconds)]);
    }

    /** Suspend the running coroutine until an in-flight curl handle completes. */
    public function awaitCurl(CurlHandle $handle): array
    {
        return Fiber::suspend(['op' => 'curl', 'handle' => $handle]);
    }

    /** Park the running coroutine until someone schedule()s it; returns the wake value. */
    public function park(): mixed
    {
        return Fiber::suspend(['op' => 'park']);
    }

    /** Block the running coroutine until $co finishes, then return/rethrow its outcome. */
    public function await(Coroutine $co): mixed
    {
        if (! $co->finished) {
            $co->joiners[] = $this->currentCoroutine;
            $this->park();
        }

        if ($co->error !== null) {
            throw $co->error;
        }

        return $co->result;
    }

    // --- the loop -------------------------------------------------------------------

    private function loop(): void
    {
        while (true) {
            while ($this->ready !== []) {
                [$co, $value] = array_shift($this->ready);

                $this->currentCoroutine = $co;
                $co->tick($value);
                $this->currentCoroutine = null;

                if ($co->finished) {
                    $this->onFinish($co);
                } else {
                    $this->onSuspend($co);
                }
            }

            if ($this->live === 0) {
                return;
            }

            if ($this->timers === [] && $this->curlWaiters === []) {
                throw new DeadlockException(
                    'All coroutines are parked with no pending timer or I/O to wake them.'
                );
            }

            $this->poll();
        }
    }

    private function onFinish(Coroutine $co): void
    {
        $this->live--;

        // Deferred cleanups run LIFO, after the body, before joiners wake.
        while ($co->deferred !== []) {
            (array_pop($co->deferred))();
        }

        foreach ($co->joiners as $joiner) {
            $this->schedule($joiner);
        }
        $co->joiners = [];
    }

    private function onSuspend(Coroutine $co): void
    {
        $op = $co->lastYield;

        switch ($op['op'] ?? null) {
            case 'sleep':
                $this->timers[] = ['until' => $op['until'], 'co' => $co];
                break;

            case 'curl':
                $this->multi ??= curl_multi_init();
                curl_multi_add_handle($this->multi, $op['handle']);
                $this->curlWaiters[spl_object_id($op['handle'])] = ['co' => $co, 'handle' => $op['handle']];
                break;

            case 'park':
                // Held by whoever registered to wake it (channel/joiner). Nothing to do.
                break;

            default:
                throw new RuntimeException('Unknown coroutine suspension instruction.');
        }
    }

    /** Block until the nearest timer or some curl activity, then wake what's ready. */
    private function poll(): void
    {
        $now = microtime(true);

        // Fire any due timers first; if we woke someone, go run them before blocking.
        $fired = false;
        foreach ($this->timers as $i => $timer) {
            if ($timer['until'] <= $now) {
                $this->schedule($timer['co']);
                unset($this->timers[$i]);
                $fired = true;
            }
        }
        if ($fired) {
            return;
        }

        $timeout = $this->nextTimerDelay($now); // seconds until nearest timer, or null

        if ($this->curlWaiters !== []) {
            $this->pollCurl($timeout);
        } elseif ($timeout !== null) {
            usleep((int) round(min($timeout, 1.0) * 1_000_000));
        }
    }

    private function pollCurl(?float $timeout): void
    {
        curl_multi_exec($this->multi, $running);

        // Block up to the nearest timer (capped so timers stay responsive).
        curl_multi_select($this->multi, $timeout !== null ? min($timeout, 1.0) : 1.0);
        curl_multi_exec($this->multi, $running);

        while ($info = curl_multi_info_read($this->multi)) {
            $id = spl_object_id($info['handle']);
            if (! isset($this->curlWaiters[$id])) {
                continue;
            }

            $waiter = $this->curlWaiters[$id];
            unset($this->curlWaiters[$id]);

            $result = $this->resolveCurl($waiter['handle'], $info['result']);
            curl_multi_remove_handle($this->multi, $waiter['handle']);
            // No curl_close(): the handle frees when this reference drops (deprecated no-op on 8.5+).

            $this->schedule($waiter['co'], $result);
        }
    }

    private function nextTimerDelay(float $now): ?float
    {
        $next = null;
        foreach ($this->timers as $timer) {
            $next = $next === null ? $timer['until'] : min($next, $timer['until']);
        }

        return $next === null ? null : max(0.0, $next - $now);
    }

    /** @return array{status: int, headers: array<string, string>, body: string, error: ?string} */
    private function resolveCurl(CurlHandle $handle, int $errno): array
    {
        $error      = $errno !== CURLE_OK ? curl_strerror($errno) : null;
        $raw        = (string) curl_multi_getcontent($handle);
        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        return [
            'status'  => $status,
            'headers' => $this->parseHeaders(substr($raw, 0, $headerSize)),
            'body'    => substr($raw, $headerSize),
            'error'   => $error,
        ];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return $headers;
    }
}
