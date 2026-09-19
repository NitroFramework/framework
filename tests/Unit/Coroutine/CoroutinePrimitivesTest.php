<?php

namespace Tests\Unit\Coroutine;

use Nitro\Coroutine\Co;
use Nitro\Coroutine\Lock;
use PHPUnit\Framework\TestCase;

/**
 * A lock coroutines can hold, and a way to see what the scheduler is doing.
 *
 * Channels coordinate by passing values. Nothing guarded a section two
 * coroutines both need to enter — building that from a capacity-1 Channel
 * works and is the sort of thing every caller would get subtly wrong.
 *
 * stats() exists because the failure mode of a cooperative scheduler is a
 * hang: nothing throws, nothing logs, the request simply never finishes.
 * Being able to ask what is ready, sleeping or waiting turns that into a
 * question with an answer.
 */
class CoroutinePrimitivesTest extends TestCase
{
    // ─── Lock ─────────────────────────────────────────────────────────────

    public function test_a_lock_serialises_a_critical_section(): void
    {
        $order = [];

        Co::run(function () use (&$order) {
            $lock = new Lock();

            $section = function (string $name) use ($lock, &$order) {
                $lock->run(function () use ($name, &$order) {
                    $order[] = "{$name} in";
                    Co::sleep(0.01);      // yields — another coroutine could interleave
                    $order[] = "{$name} out";
                });
            };

            Co::parallel([
                fn () => $section('a'),
                fn () => $section('b'),
            ]);
        });

        /* Without the lock this interleaves to a in, b in, a out, b out. */
        $this->assertSame(['a in', 'a out', 'b in', 'b out'], $order);
    }

    public function test_a_lock_is_released_even_when_the_section_throws(): void
    {
        $held = null;

        Co::run(function () use (&$held) {
            $lock = new Lock();

            try {
                $lock->run(function () {
                    throw new \RuntimeException('deliberate');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $held = $lock->isHeld();
        });

        $this->assertFalse($held, 'a throwing section must not strand the lock');
    }

    public function test_a_lock_reports_whether_it_is_held(): void
    {
        $inside = null;
        $after = null;

        Co::run(function () use (&$inside, &$after) {
            $lock = new Lock();

            $lock->run(function () use ($lock, &$inside) {
                $inside = $lock->isHeld();
            });

            $after = $lock->isHeld();
        });

        $this->assertTrue($inside);
        $this->assertFalse($after);
    }

    public function test_acquire_and_release_can_be_used_directly(): void
    {
        $states = [];

        Co::run(function () use (&$states) {
            $lock = new Lock();

            $lock->acquire();
            $states[] = $lock->isHeld();
            $lock->release();
            $states[] = $lock->isHeld();
        });

        $this->assertSame([true, false], $states);
    }

    /** Releasing what you do not hold is a mistake worth hearing about. */
    public function test_releasing_an_unheld_lock_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        Co::run(function () {
            (new Lock())->release();
        });
    }

    // ─── stats ────────────────────────────────────────────────────────────

    public function test_stats_are_available_outside_a_coroutine(): void
    {
        $stats = Co::stats();

        $this->assertArrayHasKey('running', $stats);
        $this->assertArrayHasKey('ready', $stats);
        $this->assertArrayHasKey('sleeping', $stats);
        $this->assertArrayHasKey('waitingOnCurl', $stats);
        $this->assertFalse($stats['running'], 'no scheduler is running yet');
    }

    public function test_stats_count_what_the_scheduler_is_holding(): void
    {
        $seen = null;

        Co::run(function () use (&$seen) {
            Co::go(fn () => Co::sleep(0.05));
            Co::go(fn () => Co::sleep(0.05));

            /* Let both reach their sleep. */
            Co::sleep(0.01);

            $seen = Co::stats();
        });

        $this->assertTrue($seen['running']);
        $this->assertSame(2, $seen['sleeping'], 'both spawned coroutines are parked on a timer');
    }

    public function test_the_current_coroutine_knows_its_parent(): void
    {
        $ids = null;

        Co::run(function () use (&$ids) {
            $rootId = Co::id();

            Co::await(Co::go(function () use ($rootId, &$ids) {
                $ids = ['parent' => Co::parentId(), 'root' => $rootId, 'self' => Co::id()];
            }));
        });

        $this->assertSame($ids['root'], $ids['parent']);
        $this->assertNotSame($ids['self'], $ids['parent']);
    }

    public function test_the_root_coroutine_has_no_parent(): void
    {
        $parent = null;

        Co::run(function () use (&$parent) {
            $parent = Co::parentId();
        });

        $this->assertSame(-1, $parent);
    }
}
