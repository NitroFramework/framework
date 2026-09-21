<?php

namespace Tests\Unit\Scheduling;

use Nitro\Container\Container;
use Nitro\Cache\CacheManager;
use Nitro\Console\CommandManager;
use Nitro\Queue\QueueManager;
use Nitro\Scheduling\ScheduleContext;
use Nitro\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The callbacks that run around a scheduled task.
 *
 * A task that throws still has to release whatever it set up, and the failure
 * has to reach the application rather than being swallowed by the hook.
 */
class EventHooksTest extends TestCase
{
    /** The three services a due task may reach for; none is built by a callback task. */
    private function scheduleContext(?Container $container = null): ScheduleContext
    {
        $container ??= new Container();

        return new ScheduleContext(
            static fn (): CacheManager => $container->resolve(CacheManager::class),
            static fn (): QueueManager => $container->resolve(QueueManager::class),
            static fn (): CommandManager => $container->resolve(CommandManager::class),
        );
    }
    private function container(): Container
    {
        return new Container();
    }

    public function test_before_and_after_run_around_the_task(): void
    {
        $order = [];

        $event = (new Event(function () use (&$order) {
            $order[] = 'task';

            return 'done';
        }))
            ->before(function () use (&$order) { $order[] = 'before'; })
            ->after(function () use (&$order) { $order[] = 'after'; });

        $this->assertSame('done', $event->run($this->scheduleContext()));
        $this->assertSame(['before', 'task', 'after'], $order);
    }

    public function test_then_is_an_alias_of_after(): void
    {
        $ran = false;

        (new Event(fn () => null))
            ->then(function () use (&$ran) { $ran = true; })
            ->run($this->scheduleContext());

        $this->assertTrue($ran);
    }

    public function test_on_success_receives_the_result(): void
    {
        $seen = null;

        (new Event(fn () => 'the result'))
            ->onSuccess(function ($result) use (&$seen) { $seen = $result; })
            ->run($this->scheduleContext());

        $this->assertSame('the result', $seen);
    }

    public function test_on_success_does_not_run_when_the_task_throws(): void
    {
        $ran = false;

        $event = (new Event(fn () => throw new RuntimeException('nope')))
            ->onSuccess(function () use (&$ran) { $ran = true; });

        try {
            $event->run($this->scheduleContext());
        } catch (RuntimeException) {
            //
        }

        $this->assertFalse($ran);
    }

    public function test_on_failure_receives_the_exception(): void
    {
        $seen = null;

        $event = (new Event(fn () => throw new RuntimeException('it broke')))
            ->onFailure(function ($exception) use (&$seen) { $seen = $exception; });

        try {
            $event->run($this->scheduleContext());
        } catch (RuntimeException) {
            //
        }

        $this->assertInstanceOf(RuntimeException::class, $seen);
        $this->assertSame('it broke', $seen->getMessage());
    }

    /** A hook must not swallow the failure it was told about. */
    public function test_a_failure_still_reaches_the_caller(): void
    {
        $event = (new Event(fn () => throw new RuntimeException('it broke')))
            ->onFailure(fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('it broke');

        $event->run($this->scheduleContext());
    }

    /** Cleanup has to happen even when the task fails. */
    public function test_after_runs_even_when_the_task_throws(): void
    {
        $ran = false;

        $event = (new Event(fn () => throw new RuntimeException('nope')))
            ->after(function () use (&$ran) { $ran = true; });

        try {
            $event->run($this->scheduleContext());
        } catch (RuntimeException) {
            //
        }

        $this->assertTrue($ran);
    }
}
