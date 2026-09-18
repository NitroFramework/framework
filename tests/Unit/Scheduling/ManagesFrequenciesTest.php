<?php

namespace Tests\Unit\Scheduling;

use DateTimeImmutable;
use Nitro\Scheduling\Event;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary for saying when a task runs.
 *
 * Each method is checked by the expression it produces, because that is what
 * the scheduler actually evaluates — two ways of describing the same schedule
 * have to compile to the same five fields.
 */
class ManagesFrequenciesTest extends TestCase
{
    private function event(): Event
    {
        return new Event(fn () => null);
    }

    // ─── Minutes ──────────────────────────────────────────

    public function test_minute_frequencies(): void
    {
        $this->assertSame('* * * * *', $this->event()->everyMinute()->expression());
        $this->assertSame('*/2 * * * *', $this->event()->everyTwoMinutes()->expression());
        $this->assertSame('*/3 * * * *', $this->event()->everyThreeMinutes()->expression());
        $this->assertSame('*/4 * * * *', $this->event()->everyFourMinutes()->expression());
        $this->assertSame('*/5 * * * *', $this->event()->everyFiveMinutes()->expression());
        $this->assertSame('*/10 * * * *', $this->event()->everyTenMinutes()->expression());
        $this->assertSame('*/15 * * * *', $this->event()->everyFifteenMinutes()->expression());
        $this->assertSame('0,30 * * * *', $this->event()->everyThirtyMinutes()->expression());
    }

    // ─── Hours ────────────────────────────────────────────

    public function test_hour_frequencies(): void
    {
        $this->assertSame('0 * * * *', $this->event()->hourly()->expression());
        $this->assertSame('15 * * * *', $this->event()->hourlyAt(15)->expression());
        $this->assertSame('0 */2 * * *', $this->event()->everyTwoHours()->expression());
        $this->assertSame('0 */3 * * *', $this->event()->everyThreeHours()->expression());
        $this->assertSame('0 */4 * * *', $this->event()->everyFourHours()->expression());
        $this->assertSame('0 */6 * * *', $this->event()->everySixHours()->expression());
        $this->assertSame('0 1-23/2 * * *', $this->event()->everyOddHour()->expression());
    }

    public function test_hourly_at_accepts_several_offsets(): void
    {
        $this->assertSame('5,20,35 * * * *', $this->event()->hourlyAt([5, 20, 35])->expression());
    }

    // ─── Days ─────────────────────────────────────────────

    public function test_daily_frequencies(): void
    {
        $this->assertSame('0 0 * * *', $this->event()->daily()->expression());
        $this->assertSame('30 2 * * *', $this->event()->dailyAt('02:30')->expression());
        $this->assertSame('0 1,13 * * *', $this->event()->twiceDaily()->expression());
        $this->assertSame('0 3,15 * * *', $this->event()->twiceDaily(3, 15)->expression());
        $this->assertSame('30 3,15 * * *', $this->event()->twiceDailyAt(3, 15, 30)->expression());
    }

    /** dailyAt and at are the same thing said two ways. */
    public function test_at_is_an_alias_of_daily_at(): void
    {
        $this->assertSame(
            $this->event()->dailyAt('09:15')->expression(),
            $this->event()->at('09:15')->expression()
        );
    }

    public function test_day_of_week_helpers(): void
    {
        $this->assertSame('* * * * 1', $this->event()->mondays()->expression());
        $this->assertSame('* * * * 2', $this->event()->tuesdays()->expression());
        $this->assertSame('* * * * 3', $this->event()->wednesdays()->expression());
        $this->assertSame('* * * * 4', $this->event()->thursdays()->expression());
        $this->assertSame('* * * * 5', $this->event()->fridays()->expression());
        $this->assertSame('* * * * 6', $this->event()->saturdays()->expression());
        $this->assertSame('* * * * 0', $this->event()->sundays()->expression());
        $this->assertSame('* * * * 1-5', $this->event()->weekdays()->expression());
        $this->assertSame('* * * * 0,6', $this->event()->weekends()->expression());
    }

    public function test_days_accepts_a_list_or_varargs(): void
    {
        $this->assertSame('* * * * 1,3,5', $this->event()->days([1, 3, 5])->expression());
        $this->assertSame('* * * * 1,3,5', $this->event()->days(1, 3, 5)->expression());
    }

    // ─── Weeks, months, years ─────────────────────────────

    public function test_longer_frequencies(): void
    {
        $this->assertSame('0 0 * * 0', $this->event()->weekly()->expression());
        $this->assertSame('30 8 * * 1', $this->event()->weeklyOn(1, '8:30')->expression());
        $this->assertSame('0 0 1 * *', $this->event()->monthly()->expression());
        $this->assertSame('0 15 4 * *', $this->event()->monthlyOn(4, '15:00')->expression());
        $this->assertSame('0 0 1,16 * *', $this->event()->twiceMonthly()->expression());
        $this->assertSame('0 0 1 1-12/3 *', $this->event()->quarterly()->expression());
        $this->assertSame('0 0 1 1 *', $this->event()->yearly()->expression());
        $this->assertSame('0 0 5 6 *', $this->event()->yearlyOn(6, 5)->expression());
    }

    // ─── Windows ──────────────────────────────────────────

    public function test_between_restricts_to_a_window(): void
    {
        $now = new DateTimeImmutable('now');
        $hour = (int) $now->format('G');

        $inside = $this->event()->everyMinute()
            ->between(sprintf('%02d:00', $hour), sprintf('%02d:59', $hour));

        $outside = $this->event()->everyMinute()
            ->between(sprintf('%02d:00', ($hour + 2) % 24), sprintf('%02d:59', ($hour + 3) % 24));

        $this->assertTrue($inside->isDue($now));
        $this->assertFalse($outside->isDue($now));
    }

    public function test_unless_between_is_the_inverse(): void
    {
        $now = new DateTimeImmutable('now');
        $hour = (int) $now->format('G');

        $event = $this->event()->everyMinute()
            ->unlessBetween(sprintf('%02d:00', $hour), sprintf('%02d:59', $hour));

        $this->assertFalse($event->isDue($now));
    }

    // ─── Environments ─────────────────────────────────────

    public function test_a_task_runs_everywhere_by_default(): void
    {
        $this->assertTrue($this->event()->runsInEnvironment('production'));
        $this->assertTrue($this->event()->runsInEnvironment('local'));
    }

    public function test_environments_restrict_where_a_task_runs(): void
    {
        $event = $this->event()->environments('production');

        $this->assertTrue($event->runsInEnvironment('production'));
        $this->assertFalse($event->runsInEnvironment('local'));
    }

    // ─── Timezone ─────────────────────────────────────────

    /** A task written as 02:00 in one zone must not drift with the server's. */
    public function test_the_timezone_shifts_when_a_task_is_due(): void
    {
        $event = $this->event()->dailyAt('02:00')->timezone('Asia/Karachi');

        // 21:00 UTC is 02:00 the next day in Karachi (UTC+5).
        $this->assertTrue($event->isDue(new DateTimeImmutable('2026-09-18 21:00:00', new \DateTimeZone('UTC'))));
        $this->assertFalse($event->isDue(new DateTimeImmutable('2026-09-18 02:00:00', new \DateTimeZone('UTC'))));
    }
}
