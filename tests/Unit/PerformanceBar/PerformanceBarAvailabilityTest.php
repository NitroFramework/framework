<?php

namespace Tests\Unit\PerformanceBar;

use Nitro\PerformanceBar\PerformanceBar;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Kernel uses PerformanceBar::isAvailable() to skip the singleton allocation
 * and response-header probe on the production hot path.
 *
 * Each test runs in its own process so the static instance doesn't leak.
 */
class PerformanceBarAvailabilityTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_is_available_false_when_never_instantiated(): void
    {
        $this->assertFalse(PerformanceBar::isAvailable());
    }

    #[RunInSeparateProcess]
    public function test_is_available_false_when_instantiated_but_not_enabled(): void
    {
        PerformanceBar::getInstance();
        $this->assertFalse(PerformanceBar::isAvailable());
    }

    #[RunInSeparateProcess]
    public function test_is_available_true_after_enable(): void
    {
        PerformanceBar::getInstance()->enable();
        $this->assertTrue(PerformanceBar::isAvailable());
    }
}
