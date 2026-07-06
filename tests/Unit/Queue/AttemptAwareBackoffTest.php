<?php

namespace Tests\Unit\Queue;

use Nitro\Queue\Job;
use PHPUnit\Framework\TestCase;

/**
 * backoff() can now be attempt-aware: the Worker sets the reserved attempt count
 * before running the job, so an override like `2 ** $this->currentAttempts`
 * actually escalates. Previously currentAttempts was never populated.
 */
class AttemptAwareBackoffTest extends TestCase
{
    public function test_backoff_can_use_current_attempt(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
            public function backoff(): int
            {
                return 2 ** $this->currentAttempts;
            }
        };

        $job->setCurrentAttempts(1);
        $this->assertSame(2, $job->backoff());

        $job->setCurrentAttempts(4);
        $this->assertSame(16, $job->backoff());
    }

    public function test_attempts_accessor_reflects_the_set_value(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(0, $job->attempts());
        $job->setCurrentAttempts(3);
        $this->assertSame(3, $job->attempts());
    }
}
