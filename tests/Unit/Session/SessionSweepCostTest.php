<?php

namespace Tests\Unit\Session;

use Nitro\Session\FileSessionHandler;
use PHPUnit\Framework\TestCase;

/**
 * What a session sweep is allowed to cost.
 *
 * The sweep capped how many files it deleted but not how many it looked at,
 * and looking is the expensive part — a stat is about 96% of the cost. So the
 * case where nothing is collectible, which is the normal one for a busy
 * application, walked and stat'd the entire directory to delete nothing: half
 * a second at twenty thousand live sessions, on a lottery, in the middle of
 * serving requests.
 *
 * These tests pin the three things the fix has to hold at once: the cost does
 * not grow with the directory, a backlog still drains, and a window does not
 * hide what sits behind long-lived sessions.
 */
class SessionSweepCostTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nitro_sweep_' . bin2hex(random_bytes(4));

        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * @param int $count How many session files to lay down.
     * @param int $age   Seconds old; 0 for fresh.
     */
    private function sessions(int $count, int $age = 0, int $from = 0): void
    {
        for ($i = $from; $i < $from + $count; $i++) {
            $path = $this->directory . DIRECTORY_SEPARATOR . str_pad(dechex($i), 40, '0', STR_PAD_LEFT);

            file_put_contents($path, 'payload');

            if ($age > 0) {
                touch($path, time() - $age);
            }
        }
    }

    private function remaining(): int
    {
        return count(glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    /**
     * The regression itself: a sweep over live sessions must not scale with
     * how many there are. Compared as a ratio rather than against a wall-clock
     * budget, so the test says the same thing on a slow machine.
     */
    public function test_a_sweep_does_not_get_slower_as_the_directory_grows(): void
    {
        $handler = new FileSessionHandler($this->directory);

        $this->sessions(200);
        $small = $this->timeSweep($handler);

        $this->sessions(5000, 0, 200);
        $large = $this->timeSweep($handler);

        $this->assertSame(25 * 200, 5000, 'the large directory is 25x the small one');

        $this->assertLessThan(
            5.0,
            $large / max($small, 0.00001),
            sprintf(
                'sweeping 25x the files took %.1fx as long (%.2fms vs %.2fms) — the walk is not bounded',
                $large / max($small, 0.00001),
                $large,
                $small
            )
        );
    }

    /** Nothing collectible means nothing collected, however large the backlog. */
    public function test_a_sweep_of_live_sessions_removes_nothing(): void
    {
        $handler = new FileSessionHandler($this->directory);

        $this->sessions(1000);

        $this->assertSame(0, $handler->gc(7200, 100));
        $this->assertSame(1000, $this->remaining());
    }

    /** Bounded work must not mean work left undone. */
    public function test_a_backlog_still_drains_across_successive_sweeps(): void
    {
        $handler = new FileSessionHandler($this->directory);

        $this->sessions(1000, 86400);

        for ($sweep = 0; $sweep < 60 && $this->remaining() > 0; $sweep++) {
            $handler->gc(7200, 100);
        }

        $this->assertSame(0, $this->remaining(), 'the backlog did not drain');
    }

    /**
     * The trap in windowing: sessions someone is actively using sit at the
     * head of the directory and stay fresh, so a window that always starts at
     * the front would stat the same live files forever and never reach the
     * dead ones behind them.
     */
    public function test_expired_sessions_behind_live_ones_are_still_collected(): void
    {
        $handler = new FileSessionHandler($this->directory);

        $this->sessions(400);                 // live, at the head
        $this->sessions(200, 86400, 400);     // expired, behind them

        for ($sweep = 0; $sweep < 40 && $this->remaining() > 400; $sweep++) {
            $handler->gc(7200, 100);
        }

        $this->assertSame(400, $this->remaining(), 'expired sessions behind live ones were never reached');
    }

    /** A caller asking for no limit wants the whole directory, not a window. */
    public function test_an_unlimited_sweep_clears_everything_in_one_pass(): void
    {
        $handler = new FileSessionHandler($this->directory);

        $this->sessions(500, 86400);

        $this->assertSame(500, $handler->gc(7200, 0));
        $this->assertSame(0, $this->remaining());
    }

    private function timeSweep(FileSessionHandler $handler): float
    {
        $start = microtime(true);

        $handler->gc(7200, 100);

        return (microtime(true) - $start) * 1000;
    }
}
