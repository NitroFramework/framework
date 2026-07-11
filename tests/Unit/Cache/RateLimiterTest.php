<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\RateLimiter;
use Nitro\Cache\Repository;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private function limiter(): RateLimiter
    {
        return new RateLimiter(new Repository(new ArrayStore()));
    }

    public function test_counts_attempts_and_locks_at_cap(): void
    {
        $l = $this->limiter();

        $this->assertFalse($l->tooManyAttempts('k', 3));
        $l->hit('k', 60);
        $l->hit('k', 60);
        $this->assertSame(2, $l->attempts('k'));
        $this->assertSame(1, $l->remaining('k', 3));
        $this->assertFalse($l->tooManyAttempts('k', 3));

        $l->hit('k', 60);
        $this->assertSame(3, $l->attempts('k'));
        $this->assertTrue($l->tooManyAttempts('k', 3));
        $this->assertGreaterThan(0, $l->availableIn('k'));
    }

    public function test_hit_returns_the_running_count_via_atomic_increment(): void
    {
        $l = $this->limiter();

        // hit() returns the post-increment count (atomic increment, not get+put).
        $this->assertSame(1, $l->hit('c', 60));
        $this->assertSame(2, $l->hit('c', 60));
        $this->assertSame(3, $l->hit('c', 60));
        $this->assertSame(3, $l->attempts('c'));
    }

    public function test_clear_resets_the_key(): void
    {
        $l = $this->limiter();
        $l->hit('k', 60);
        $l->hit('k', 60);
        $l->clear('k');

        $this->assertSame(0, $l->attempts('k'));
        $this->assertFalse($l->tooManyAttempts('k', 1));
    }

    public function test_attempt_runs_callback_under_limit_and_blocks_over(): void
    {
        $l = $this->limiter();
        $ran = 0;
        $cb = function () use (&$ran) { $ran++; return 'ok'; };

        $this->assertSame('ok', $l->attempt('a', 1, $cb, 60));
        $this->assertSame(1, $ran);

        // Second call is over the cap — callback must NOT run.
        $this->assertFalse($l->attempt('a', 1, $cb, 60));
        $this->assertSame(1, $ran);
    }

    public function test_keys_are_isolated(): void
    {
        $l = $this->limiter();
        $l->hit('user-a', 60);

        $this->assertSame(1, $l->attempts('user-a'));
        $this->assertSame(0, $l->attempts('user-b'));
    }
}
