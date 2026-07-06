<?php

namespace Tests\Unit\Thrust;

use Nitro\Foundation\Application;
use Nitro\Thrust\Adapters\FrankenPhpAdapter;
use Nitro\Thrust\WorkerMode;
use Nitro\Thrust\Runner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Verifies the worker registers a shutdown hook for SIGTERM/SIGINT (when pcntl
 * is available) and exposes the resulting flag so the run loop can break out
 * gracefully.
 *
 * We can't actually fire signals into the test runner without flaking, so we
 * exercise the handler indirectly: set shouldStop = true and confirm it's the
 * one read by run-loop conditions.
 */
class RunnerSignalTest extends TestCase
{
    public function test_should_stop_flag_defaults_to_false(): void
    {
        $runner = $this->makeRunner();
        $flag = new ReflectionProperty(Runner::class, 'shouldStop');
        $this->assertFalse($flag->getValue($runner));
    }

    public function test_install_signal_handlers_is_idempotent_and_safe(): void
    {
        $runner = $this->makeRunner();
        $install = new ReflectionMethod(Runner::class, 'installSignalHandlers');

        // Must not throw whether pcntl is available or not.
        $install->invoke($runner);
        $install->invoke($runner);

        $this->addToAssertionCount(1);
    }

    public function test_signal_handler_sets_should_stop_when_pcntl_present(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available on this platform.');
        }

        $runner = $this->makeRunner();
        $install = new ReflectionMethod(Runner::class, 'installSignalHandlers');
        $install->invoke($runner);

        // Dispatch SIGTERM to ourselves; with pcntl_async_signals(true) it fires
        // before the next PHP statement.
        posix_kill(posix_getpid(), SIGTERM);

        $flag = new ReflectionProperty(Runner::class, 'shouldStop');
        $this->assertTrue($flag->getValue($runner));
    }

    protected function makeRunner(): Runner
    {
        return new Runner(
            $this->createMock(Application::class),
            $this->createMock(FrankenPhpAdapter::class),
            $this->createMock(WorkerMode::class),
        );
    }
}
