<?php

namespace Tests\Unit\Concurrency;

use Nitro\Concurrency\Concurrency;
use Nitro\Concurrency\Drivers\CoroutineDriver;
use Nitro\Concurrency\Drivers\ForkDriver;
use Nitro\Concurrency\Drivers\ProcessDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The coroutine driver, and what happens where it cannot run.
 *
 * ext-swoole does not build on Windows, so the behaviour that needs a running
 * scheduler is skipped rather than faked — a test that mocks the extension
 * proves the mock. What is asserted everywhere is the part that decides whether
 * an application breaks on a machine without it: that the driver reports its own
 * availability honestly, that asking for it by name refuses with a message
 * naming the alternatives, and that 'auto' never picks something it cannot run.
 */
class CoroutineDriverTest extends TestCase
{
    /**
     * A task is invoked through the container, so there has to be one.
     *
     * Established here rather than relied upon from whatever ran first, so the
     * file passes on its own as well as in the suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Nitro\Container\Container::setInstance(new \Nitro\Container\Container());
    }

    public function test_it_reports_whether_the_platform_can_run_coroutines(): void
    {
        $this->assertSame(extension_loaded('swoole'), CoroutineDriver::supported());
    }

    /**
     * Naming the driver where it cannot run fails at the moment it is asked
     * for, not at construction, so an application that never uses it boots.
     */
    public function test_asking_for_it_without_the_extension_names_the_alternatives(): void
    {
        if (CoroutineDriver::supported()) {
            $this->markTestSkipped('ext-swoole is present, so asking for it succeeds.');
        }

        $concurrency = new Concurrency('sync');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ext-swoole');

        $concurrency->driver('coroutine');
    }

    public function test_building_the_manager_never_touches_an_absent_extension(): void
    {
        $concurrency = new Concurrency('coroutine');

        $this->assertInstanceOf(Concurrency::class, $concurrency);
    }

    /** 'auto' has to answer with something this machine can actually run. */
    public function test_auto_chooses_a_driver_the_platform_supports(): void
    {
        $driver = (new Concurrency('sync'))->driver('auto');

        $expected = match (true) {
            CoroutineDriver::supported() => CoroutineDriver::class,
            ForkDriver::supported()      => ForkDriver::class,
            default                      => ProcessDriver::class,
        };

        $this->assertInstanceOf($expected, $driver);
    }

    public function test_an_unknown_driver_is_still_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Concurrency('sync'))->driver('fibers');
    }

    public function test_no_tasks_is_no_work_and_no_extension_needed(): void
    {
        $this->assertSame([], (new CoroutineDriver())->run([]));
    }

    // ─── Needs a scheduler ────────────────────────────────

    public function test_it_runs_tasks_and_keys_the_results(): void
    {
        $this->skipWithoutSwoole();

        $results = (new CoroutineDriver())->run([
            'first'  => static fn (): string => 'a',
            'second' => static fn (): string => 'b',
        ]);

        $this->assertSame(['first' => 'a', 'second' => 'b'], $results);
    }

    public function test_results_come_back_in_the_order_they_were_given(): void
    {
        $this->skipWithoutSwoole();

        $results = (new CoroutineDriver())->run([
            'slow' => static function (): string { usleep(20_000); return 'slow'; },
            'fast' => static fn (): string => 'fast',
        ]);

        $this->assertSame(['slow', 'fast'], array_keys($results));
    }

    /** A task that throws must not leave the caller waiting on the group. */
    public function test_a_failing_task_surfaces_rather_than_hanging(): void
    {
        $this->skipWithoutSwoole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new CoroutineDriver())->run([
            'ok'  => static fn (): string => 'fine',
            'bad' => static fn () => throw new RuntimeException('boom'),
        ]);
    }

    private function skipWithoutSwoole(): void
    {
        if (! CoroutineDriver::supported()) {
            $this->markTestSkipped('Needs ext-swoole, which this platform does not have.');
        }
    }
}
