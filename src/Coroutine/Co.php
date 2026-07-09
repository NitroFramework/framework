<?php

namespace Nitro\Coroutine;

use Fiber;
use Nitro\Coroutine\Exceptions\ParallelExecutionException;
use RuntimeException;
use Throwable;

/**
 * The coroutine API — Nitro's `go`/`await` surface, built on native Fibers and our
 * own Scheduler (no Swoole). A static entry point rather than a container facade,
 * because the scheduler is per-run, not a shared singleton service.
 *
 *   // The 90% case — fan out, overlap the I/O, collect keyed results:
 *   [$a, $b] = array_values(Co::parallel([
 *       fn () => Http::get('https://a.test'),
 *       fn () => Http::get('https://b.test'),
 *   ]));
 *
 *   // Full control — spawn, do other work, await when you need the value:
 *   Co::run(function () {
 *       $job = Co::go(fn () => Http::get('https://slow.test'));
 *       do_local_work();
 *       $res = Co::await($job);
 *   });
 *
 * Reminder: only pollable I/O overlaps (HTTP via curl_multi, Co::sleep). A blocking
 * PDO call inside a coroutine still blocks the whole scheduler — see Scheduler.
 */
final class Co
{
    /**
     * Enter (or nest into) a scheduler, run $main as the root coroutine, and return
     * its value. This is the boundary between blocking code and the coroutine world.
     */
    public static function run(callable $main): mixed
    {
        $scheduler = Scheduler::current();

        // Already inside a scheduler — run as a child coroutine and await it, so the
        // outer loop keeps servicing everything else meanwhile.
        if ($scheduler !== null) {
            return $scheduler->await($scheduler->spawn($main));
        }

        return (new Scheduler())->runRoot($main);
    }

    /** Spawn a concurrent coroutine in the current scheduler; returns its handle. */
    public static function go(callable $callable): Coroutine
    {
        return self::scheduler('go')->spawn($callable);
    }

    /** Block the running coroutine until $co finishes; returns/rethrows its outcome. */
    public static function await(Coroutine $co): mixed
    {
        return self::scheduler('await')->await($co);
    }

    /**
     * Run all callables concurrently and return their results keyed as input. On any
     * failure, throws ParallelExecutionException carrying every result and error.
     *
     * @param  array<int|string, callable> $callables
     * @param  int $concurrency  Max in flight at once (0 = unlimited).
     * @return array<int|string, mixed>
     */
    public static function parallel(array $callables, int $concurrency = 0): array
    {
        return self::run(function () use ($callables, $concurrency) {
            $wg      = new WaitGroup();
            $results = [];
            $errors  = [];
            $limiter = $concurrency > 0 ? new Channel($concurrency) : null;

            foreach ($callables as $key => $callable) {
                $wg->add();
                $limiter?->push(true);

                self::go(function () use ($callable, $key, $wg, $limiter, &$results, &$errors) {
                    try {
                        $results[$key] = $callable();
                    } catch (Throwable $e) {
                        $errors[$key] = $e;
                    } finally {
                        $limiter?->pop();
                        $wg->done();
                    }
                });
            }

            $wg->wait();

            if ($errors !== []) {
                throw new ParallelExecutionException($results, $errors);
            }

            // Return in the caller's key order regardless of completion order.
            $ordered = [];
            foreach ($callables as $key => $_) {
                $ordered[$key] = $results[$key] ?? null;
            }

            return $ordered;
        });
    }

    /** Suspend the running coroutine for $seconds; a plain usleep outside one. */
    public static function sleep(float $seconds): void
    {
        $scheduler = Scheduler::current();
        if ($scheduler !== null && Fiber::getCurrent() !== null) {
            $scheduler->sleep($seconds);

            return;
        }

        usleep((int) round(max(0.0, $seconds) * 1_000_000));
    }

    /** Register a callback to run when the current coroutine ends (LIFO). */
    public static function defer(callable $callback): void
    {
        $co = self::scheduler('defer')->currentCoroutine()
            ?? throw new RuntimeException('Co::defer() must run inside a coroutine.');

        $co->deferred[] = $callback;
    }

    /** A new channel for passing values between coroutines (0 = unbuffered). */
    public static function channel(int $capacity = 0): Channel
    {
        return new Channel($capacity);
    }

    /** The running coroutine's id, or -1 outside a coroutine. */
    public static function id(): int
    {
        return Scheduler::current()?->currentCoroutine()?->id ?? -1;
    }

    /** Whether the caller is executing inside a coroutine. */
    public static function inCoroutine(): bool
    {
        return Scheduler::current()?->currentCoroutine() !== null && Fiber::getCurrent() !== null;
    }

    private static function scheduler(string $method): Scheduler
    {
        return Scheduler::current()
            ?? throw new RuntimeException("Co::{$method}() must run inside Co::run()/Co::parallel().");
    }
}
