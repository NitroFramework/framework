<?php

namespace Tests\Unit\Scheduling;

use DateTimeImmutable;
use Nitro\Container\Container;
use Nitro\Scheduling\CronExpression;
use Nitro\Scheduling\Event;
use Nitro\Scheduling\Schedule;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    private function at(string $datetime): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime);
    }

    private function event(callable $task = null): Event
    {
        return new Event($task ?? static fn () => null, 'callback');
    }

    public function test_cron_wildcards_steps_and_exact(): void
    {
        $this->assertTrue((new CronExpression('* * * * *'))->isDue($this->at('2026-06-15 13:30:00')));

        $this->assertTrue((new CronExpression('*/5 * * * *'))->isDue($this->at('2026-06-15 13:30:00')));
        $this->assertFalse((new CronExpression('*/5 * * * *'))->isDue($this->at('2026-06-15 13:31:00')));

        $this->assertTrue((new CronExpression('30 13 * * *'))->isDue($this->at('2026-06-15 13:30:00')));
        $this->assertFalse((new CronExpression('30 13 * * *'))->isDue($this->at('2026-06-15 14:30:00')));

        $this->assertTrue((new CronExpression('0 0 1 * *'))->isDue($this->at('2026-06-01 00:00:00')));
        $this->assertFalse((new CronExpression('0 0 1 * *'))->isDue($this->at('2026-06-02 00:00:00')));
    }

    public function test_cron_ranges_and_lists(): void
    {
        // Business hours (9-17), on the hour, any weekday.
        $this->assertTrue((new CronExpression('0 9-17 * * *'))->isDue($this->at('2026-06-15 09:00:00')));
        $this->assertTrue((new CronExpression('0 9-17 * * *'))->isDue($this->at('2026-06-15 17:00:00')));
        $this->assertFalse((new CronExpression('0 9-17 * * *'))->isDue($this->at('2026-06-15 18:00:00')));

        $this->assertTrue((new CronExpression('0 0 1,15 * *'))->isDue($this->at('2026-06-15 00:00:00')));
    }

    public function test_frequency_helpers_build_the_expression(): void
    {
        $this->assertSame('* * * * *', $this->event()->everyMinute()->expression());
        $this->assertSame('*/5 * * * *', $this->event()->everyFiveMinutes()->expression());
        $this->assertSame('0 * * * *', $this->event()->hourly()->expression());
        $this->assertSame('30 13 * * *', $this->event()->dailyAt('13:30')->expression());
        $this->assertSame('0 0 * * *', $this->event()->daily()->expression());
        $this->assertSame('0 0 * * 0', $this->event()->weekly()->expression());
        $this->assertSame('0 0 1 * *', $this->event()->monthly()->expression());
        $this->assertSame('* * * * 1-5', $this->event()->weekdays()->expression());
    }

    public function test_event_is_due_and_runs_its_callback(): void
    {
        $ran = false;
        $event = $this->event(function () use (&$ran): void { $ran = true; })->dailyAt('13:30');

        $this->assertTrue($event->isDue($this->at('2026-06-15 13:30:00')));
        $this->assertFalse($event->isDue($this->at('2026-06-15 13:31:00')));

        $event->run(new Container());
        $this->assertTrue($ran);
    }

    public function test_when_and_skip_constraints(): void
    {
        $moment = $this->at('2026-06-15 13:30:00');

        $this->assertFalse($this->event()->everyMinute()->when(static fn () => false)->isDue($moment));
        $this->assertTrue($this->event()->everyMinute()->when(static fn () => true)->isDue($moment));
        $this->assertFalse($this->event()->everyMinute()->skip(static fn () => true)->isDue($moment));
    }

    public function test_schedule_returns_only_due_events(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn () => null)->dailyAt('13:30')->description('afternoon');
        $schedule->call(static fn () => null)->dailyAt('09:00')->description('morning');

        $due = $schedule->dueEvents($this->at('2026-06-15 13:30:00'));

        $this->assertCount(1, $due);
        $this->assertSame('afternoon', $due[0]->getDescription());
    }
}
