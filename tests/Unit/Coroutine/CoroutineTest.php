<?php

namespace Tests\Unit\Coroutine;

use Nitro\Coroutine\Co;
use Nitro\Coroutine\Context;
use Nitro\Coroutine\Exceptions\DeadlockException;
use Nitro\Coroutine\Exceptions\ParallelExecutionException;
use PHPUnit\Framework\TestCase;

class CoroutineTest extends TestCase
{
    public function test_run_returns_the_root_result(): void
    {
        $this->assertSame(42, Co::run(fn () => 42));
    }

    public function test_run_propagates_exceptions(): void
    {
        $this->expectExceptionMessage('boom');
        Co::run(fn () => throw new \RuntimeException('boom'));
    }

    public function test_go_and_await_returns_the_coroutine_value(): void
    {
        $result = Co::run(function () {
            $job = Co::go(fn () => 7 * 6);

            return Co::await($job);
        });

        $this->assertSame(42, $result);
    }

    public function test_await_rethrows_the_coroutine_exception(): void
    {
        $this->expectExceptionMessage('inner failure');

        Co::run(function () {
            $job = Co::go(fn () => throw new \LogicException('inner failure'));
            Co::await($job);
        });
    }

    /**
     * The core proof: three coroutines each sleeping 50ms run CONCURRENTLY, so the
     * whole batch finishes in ~50ms, not 150ms. If the scheduler serialised them
     * this would take 3x as long.
     */
    public function test_parallel_tasks_actually_overlap(): void
    {
        $start = microtime(true);

        $results = Co::parallel([
            'a' => function () { Co::sleep(0.05); return 'a'; },
            'b' => function () { Co::sleep(0.05); return 'b'; },
            'c' => function () { Co::sleep(0.05); return 'c'; },
        ]);

        $elapsed = microtime(true) - $start;

        $this->assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c'], $results);
        $this->assertLessThan(0.13, $elapsed, 'Tasks did not overlap — scheduler ran them serially.');
    }

    public function test_parallel_preserves_input_key_order(): void
    {
        $results = Co::parallel([
            'slow' => function () { Co::sleep(0.04); return 1; },
            'fast' => function () { Co::sleep(0.01); return 2; },
        ]);

        $this->assertSame(['slow', 'fast'], array_keys($results));
    }

    public function test_parallel_aggregates_failures(): void
    {
        try {
            Co::parallel([
                'ok'   => fn () => 'good',
                'bad'  => fn () => throw new \RuntimeException('nope'),
            ]);
            $this->fail('Expected ParallelExecutionException.');
        } catch (ParallelExecutionException $e) {
            $this->assertArrayHasKey('bad', $e->throwables());
            $this->assertSame('good', $e->results()['ok']);
        }
    }

    public function test_concurrency_limit_caps_in_flight_tasks(): void
    {
        $active = 0;
        $peak   = 0;

        $tasks = [];
        for ($i = 0; $i < 6; $i++) {
            $tasks[] = function () use (&$active, &$peak) {
                $active++;
                $peak = max($peak, $active);
                Co::sleep(0.02);
                $active--;

                return true;
            };
        }

        Co::parallel($tasks, concurrency: 2);

        $this->assertLessThanOrEqual(2, $peak);
        $this->assertGreaterThan(0, $peak);
    }

    public function test_channel_hands_values_between_coroutines(): void
    {
        $received = Co::run(function () {
            $chan = Co::channel(1);
            $out  = [];

            $producer = Co::go(function () use ($chan) {
                foreach ([1, 2, 3] as $n) {
                    $chan->push($n);
                }
                $chan->push(null); // sentinel
            });

            while (($value = $chan->pop()) !== null) {
                $out[] = $value;
            }

            Co::await($producer);

            return $out;
        });

        $this->assertSame([1, 2, 3], $received);
    }

    public function test_unbuffered_channel_rendezvous(): void
    {
        $result = Co::run(function () {
            $chan = Co::channel(); // capacity 0
            Co::go(fn () => $chan->push('hello'));

            return $chan->pop();
        });

        $this->assertSame('hello', $result);
    }

    public function test_context_is_isolated_per_coroutine(): void
    {
        $seen = Co::run(function () {
            $a = Co::go(function () {
                Context::set('id', 'A');
                Co::sleep(0.02); // let B run and set its own value
                return Context::get('id');
            });
            $b = Co::go(function () {
                Context::set('id', 'B');
                return Context::get('id');
            });

            return [Co::await($a), Co::await($b)];
        });

        $this->assertSame(['A', 'B'], $seen);
    }

    public function test_defer_runs_when_coroutine_ends(): void
    {
        $log = [];

        Co::run(function () use (&$log) {
            Co::await(Co::go(function () use (&$log) {
                Co::defer(function () use (&$log) { $log[] = 'deferred'; });
                $log[] = 'body';
            }));
        });

        $this->assertSame(['body', 'deferred'], $log);
    }

    public function test_id_and_in_coroutine(): void
    {
        $this->assertFalse(Co::inCoroutine());
        $this->assertSame(-1, Co::id());

        $inside = Co::run(fn () => [Co::inCoroutine(), Co::id() > 0]);
        $this->assertSame([true, true], $inside);
    }

    /** A thousand coroutines all "waiting on I/O" finish together, not one-by-one. */
    public function test_scales_to_a_thousand_overlapping_coroutines(): void
    {
        $start = microtime(true);

        $tasks = [];
        for ($i = 0; $i < 1000; $i++) {
            $tasks[] = fn () => Co::sleep(0.02);
        }
        $results = Co::parallel($tasks);

        $elapsed = microtime(true) - $start;

        $this->assertCount(1000, $results);
        // Serial would be 1000 * 20ms = 20s; overlapped it's well under a second.
        $this->assertLessThan(2.0, $elapsed);
    }

    public function test_bounded_concurrency_holds_across_many_tasks(): void
    {
        $active = 0;
        $peak   = 0;
        $ran    = 0;

        $tasks = [];
        for ($i = 0; $i < 1000; $i++) {
            $tasks[] = function () use (&$active, &$peak, &$ran) {
                $active++;
                $peak = max($peak, $active);
                Co::sleep(0.001);
                $active--;
                $ran++;
            };
        }

        Co::parallel($tasks, concurrency: 50);

        $this->assertSame(1000, $ran);
        $this->assertLessThanOrEqual(50, $peak);
    }

    public function test_deadlock_is_detected(): void
    {
        $this->expectException(DeadlockException::class);

        Co::run(function () {
            $chan = Co::channel();
            $chan->pop(); // nothing will ever push — no timer, no I/O
        });
    }
}
