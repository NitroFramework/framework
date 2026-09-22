<?php

namespace Tests\Unit\Scheduling;

use Nitro\Cache\CacheManager;
use Nitro\Console\CommandManager;
use Nitro\Container\Container;
use Nitro\Queue\QueueManager;
use Nitro\Scheduling\Event;
use Nitro\Scheduling\ScheduleContext;
use PHPUnit\Framework\TestCase;

/**
 * The two constraints that make a schedule safe to run on more than one
 * instance: one run at a time, and one instance per due minute.
 */
class EventLockingTest extends TestCase
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
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());

        $this->container = Container::getInstance();

        // A store every event in a test shares, standing in for the redis or
        // database store these constraints need in production.
        $cache = new CacheManager([
            'default' => 'array',
            'prefix'  => 'test:',
            'stores'  => ['array' => ['driver' => 'array']],
        ]);

        $this->container->instance(CacheManager::class, $cache);
        $this->container->alias(CacheManager::class, 'cache');
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    private function event(callable $task): Event
    {
        return (new Event($task, 'callback'))->everyMinute()->description('reporting');
    }

    // ─── Overlapping ──────────────────────────────────────

    public function test_a_task_runs_normally_without_the_constraint(): void
    {
        $runs = 0;

        $event = $this->event(function () use (&$runs): string {
            $runs++;

            return 'done';
        });

        $this->assertSame('done', $event->run($this->scheduleContext($this->container)));
        $this->assertSame('done', $event->run($this->scheduleContext($this->container)));
        $this->assertSame(2, $runs);
    }

    public function test_the_lock_is_released_when_the_task_finishes(): void
    {
        $runs = 0;

        $event = $this->event(function () use (&$runs): void {
            $runs++;
        })->withoutOverlapping();

        $event->run($this->scheduleContext($this->container));
        $event->run($this->scheduleContext($this->container));

        $this->assertSame(2, $runs);
    }

    /** The point of the constraint: a task already in flight is not restarted. */
    public function test_a_task_already_running_is_not_started_again(): void
    {
        $inner = $this->event(static fn (): string => 'inner')->withoutOverlapping();
        $attempts = 0;

        $outer = $this->event(function () use ($inner, &$attempts): mixed {
            $attempts++;

            return $inner->run($this->scheduleContext($this->container));
        })->withoutOverlapping();

        $this->assertNull($outer->run($this->scheduleContext($this->container)));
        $this->assertSame(1, $attempts);
    }

    public function test_a_throw_does_not_strand_the_lock(): void
    {
        $event = $this->event(static function (): void {
            throw new \RuntimeException('boom');
        })->withoutOverlapping();

        try {
            $event->run($this->scheduleContext($this->container));
        } catch (\RuntimeException) {
            // expected
        }

        $ran = false;

        $after = $this->event(function () use (&$ran): void {
            $ran = true;
        })->withoutOverlapping();

        $after->run($this->scheduleContext($this->container));

        $this->assertTrue($ran);
    }

    // ─── One instance ─────────────────────────────────────

    public function test_only_the_first_claim_of_a_minute_runs(): void
    {
        $runs = 0;

        $task = function () use (&$runs): string {
            $runs++;

            return 'done';
        };

        $first = $this->event($task)->onOneServer();
        $second = $this->event($task)->onOneServer();

        $this->assertSame('done', $first->run($this->scheduleContext($this->container)));
        $this->assertNull($second->run($this->scheduleContext($this->container)));
        $this->assertSame(1, $runs);
    }

    /** Two different tasks must not block each other. */
    public function test_different_tasks_claim_different_locks(): void
    {
        $runs = 0;

        $task = function () use (&$runs): string {
            $runs++;

            return 'done';
        };

        $reports = (new Event($task, 'callback'))->everyMinute()->description('reports')->onOneServer();
        $prune = (new Event($task, 'callback'))->everyMinute()->description('prune')->onOneServer();

        $this->assertSame('done', $reports->run($this->scheduleContext($this->container)));
        $this->assertSame('done', $prune->run($this->scheduleContext($this->container)));
        $this->assertSame(2, $runs);
    }

    // ─── Naming ───────────────────────────────────────────

    public function test_a_mutex_name_is_stable_across_instances(): void
    {
        $this->assertSame(
            $this->event(static fn (): null => null)->mutexName(),
            $this->event(static fn (): null => null)->mutexName(),
        );
    }

    public function test_the_schedule_changes_the_mutex_name(): void
    {
        $hourly = (new Event(static fn (): null => null, 'callback'))->hourly()->description('reports');
        $daily = (new Event(static fn (): null => null, 'callback'))->daily()->description('reports');

        $this->assertNotSame($hourly->mutexName(), $daily->mutexName());
    }
}
