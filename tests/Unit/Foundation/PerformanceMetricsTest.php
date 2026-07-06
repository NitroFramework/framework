<?php

namespace Tests\Unit\Foundation;

use Nitro\PerformanceBar\PerformanceMetrics;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Verifies PerformanceMetrics is gated by APP_DEBUG / ?performance=1 / explicit
 * override, rather than the previous force-enable-always behavior.
 *
 * Each test runs in its own process so the static $enabled state and $_ENV /
 * $_GET state do not leak.
 */
class PerformanceMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG'], $_GET['performance']);
        putenv('APP_DEBUG');
    }

    #[RunInSeparateProcess]
    public function test_disabled_when_no_debug_flag(): void
    {
        PerformanceMetrics::start();
        $this->assertFalse(PerformanceMetrics::isEnabled());
        $this->assertSame([], PerformanceMetrics::getMetrics());
    }

    #[RunInSeparateProcess]
    public function test_enabled_when_env_debug_truthy(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        PerformanceMetrics::start();
        $this->assertTrue(PerformanceMetrics::isEnabled());
    }

    #[RunInSeparateProcess]
    public function test_enabled_when_query_param_present(): void
    {
        $_GET['performance'] = '1';
        PerformanceMetrics::start();
        $this->assertTrue(PerformanceMetrics::isEnabled());
    }

    #[RunInSeparateProcess]
    public function test_explicit_override_wins_over_env(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        PerformanceMetrics::start(false);
        $this->assertFalse(PerformanceMetrics::isEnabled());
    }

    #[RunInSeparateProcess]
    public function test_explicit_true_forces_on(): void
    {
        PerformanceMetrics::start(true);
        $this->assertTrue(PerformanceMetrics::isEnabled());
    }

    #[RunInSeparateProcess]
    public function test_debug_falsy_values_are_disabled(): void
    {
        $_ENV['APP_DEBUG'] = 'false';
        PerformanceMetrics::start();
        $this->assertFalse(PerformanceMetrics::isEnabled());

        $_ENV['APP_DEBUG'] = '0';
        PerformanceMetrics::start();
        $this->assertFalse(PerformanceMetrics::isEnabled());
    }

    #[RunInSeparateProcess]
    public function test_elapsed_time_works_even_when_disabled(): void
    {
        // The @elapsed_time Blade directive must work in production too, so
        // start() always records the baseline regardless of $enabled.
        PerformanceMetrics::start(false);

        usleep(2000); // 2ms

        $this->assertFalse(PerformanceMetrics::isEnabled());
        $this->assertGreaterThan(0.0, PerformanceMetrics::getElapsedTime());
    }

    #[RunInSeparateProcess]
    public function test_get_metrics_still_empty_when_disabled(): void
    {
        // start() recording the baseline must not turn the full metrics
        // collection back on — that's reserved for debug/perf mode.
        PerformanceMetrics::start(false);
        $this->assertSame([], PerformanceMetrics::getMetrics());
    }
}
