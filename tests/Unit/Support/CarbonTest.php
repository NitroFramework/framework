<?php

namespace Tests\Unit\Support;

use Nitro\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The date type: construction, components, arithmetic, boundaries, comparison
 * and differences.
 */
class CarbonTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Construction ─────────────────────────────────────

    public function test_parse_and_components(): void
    {
        $date = Carbon::parse('2026-03-15 14:30:45');

        $this->assertSame(2026, $date->year);
        $this->assertSame(3, $date->month);
        $this->assertSame(15, $date->day);
        $this->assertSame(14, $date->hour);
        $this->assertSame(30, $date->minute);
        $this->assertSame(45, $date->second);
        $this->assertSame(1, $date->quarter);
        $this->assertSame(31, $date->daysInMonth);
    }

    public function test_create(): void
    {
        $this->assertSame(
            '2026-03-15 14:30:45',
            Carbon::create(2026, 3, 15, 14, 30, 45)->toDateTimeString()
        );
    }

    public function test_create_from_timestamp(): void
    {
        $this->assertSame(1000000000, Carbon::createFromTimestamp(1000000000)->getTimestamp());
        $this->assertSame(1000000000, Carbon::createFromTimestampMs(1000000000123)->getTimestamp());
    }

    public function test_make_from_anything(): void
    {
        $this->assertNull(Carbon::make(null));
        $this->assertNull(Carbon::make(''));
        $this->assertSame('2026-03-15', Carbon::make('2026-03-15')->toDateString());
        $this->assertSame(1000000000, Carbon::make(1000000000)->getTimestamp());
        $this->assertSame(
            '2026-03-15',
            Carbon::make(new \DateTimeImmutable('2026-03-15'))->toDateString()
        );
    }

    public function test_instance_preserves_the_moment(): void
    {
        $source = new \DateTimeImmutable('2026-03-15 14:30:45');

        $this->assertSame('2026-03-15 14:30:45', Carbon::instance($source)->toDateTimeString());
    }

    // ─── Test clock ───────────────────────────────────────

    public function test_set_test_now_freezes_now(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $this->assertSame('2026-01-01 00:00:00', Carbon::now()->toDateTimeString());
        $this->assertSame('2026-01-01', Carbon::today()->toDateString());
        $this->assertSame('2026-01-02', Carbon::tomorrow()->toDateString());
        $this->assertSame('2025-12-31', Carbon::yesterday()->toDateString());
        $this->assertTrue(Carbon::hasTestNow());
    }

    public function test_releasing_the_test_clock(): void
    {
        Carbon::setTestNow('2026-01-01');
        Carbon::setTestNow();

        $this->assertFalse(Carbon::hasTestNow());
    }

    /** A relative string is resolved against the frozen clock. */
    public function test_relative_parse_uses_the_test_clock(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $this->assertSame('2026-01-02', Carbon::parse('tomorrow')->toDateString());
        $this->assertSame('2026-01-08', Carbon::parse('+7 days')->toDateString());
    }

    // ─── Immutability ─────────────────────────────────────

    /** Every operation returns a new instance and leaves the receiver alone. */
    public function test_operations_do_not_mutate(): void
    {
        $date = Carbon::parse('2026-03-15 12:00:00');

        $date->addDay();
        $date->startOfMonth();
        $date->addYears(5);

        $this->assertSame('2026-03-15 12:00:00', $date->toDateTimeString());
    }

    public function test_copy_returns_an_equal_instance(): void
    {
        $date = Carbon::parse('2026-03-15');

        $this->assertNotSame($date, $date->copy());
        $this->assertTrue($date->eq($date->copy()));
    }

    // ─── Arithmetic ───────────────────────────────────────

    public function test_add_and_sub(): void
    {
        $date = Carbon::parse('2026-03-15 12:00:00');

        $this->assertSame('2026-03-16 12:00:00', $date->addDay()->toDateTimeString());
        $this->assertSame('2026-03-18 12:00:00', $date->addDays(3)->toDateTimeString());
        $this->assertSame('2026-03-14 12:00:00', $date->subDay()->toDateTimeString());
        $this->assertSame('2026-03-15 15:00:00', $date->addHours(3)->toDateTimeString());
        $this->assertSame('2026-03-22 12:00:00', $date->addWeek()->toDateTimeString());
        $this->assertSame('2027-03-15 12:00:00', $date->addYear()->toDateTimeString());
        $this->assertSame('2026-03-15 12:30:00', $date->addMinutes(30)->toDateTimeString());
    }

    public function test_adding_zero_is_a_no_op(): void
    {
        $date = Carbon::parse('2026-03-15');

        $this->assertSame('2026-03-15', $date->addDays(0)->toDateString());
    }

    /** PHP's own month arithmetic rolls past the end of a short month. */
    public function test_month_overflow_matches_php(): void
    {
        $this->assertSame('2026-03-03', Carbon::parse('2026-01-31')->addMonth()->toDateString());
    }

    public function test_no_overflow_variant_clamps_to_the_month_end(): void
    {
        $this->assertSame(
            '2026-02-28',
            Carbon::parse('2026-01-31')->addMonthsNoOverflow(1)->toDateString()
        );
    }

    public function test_leap_year_arithmetic(): void
    {
        $this->assertSame('2024-02-29', Carbon::parse('2024-02-28')->addDay()->toDateString());
        $this->assertTrue(Carbon::parse('2024-06-01')->isLeapYear());
        $this->assertFalse(Carbon::parse('2026-06-01')->isLeapYear());
    }

    // ─── Boundaries ───────────────────────────────────────

    public function test_day_boundaries(): void
    {
        $date = Carbon::parse('2026-03-15 14:30:45');

        $this->assertSame('2026-03-15 00:00:00', $date->startOfDay()->toDateTimeString());
        $this->assertSame('2026-03-15 23:59:59', $date->endOfDay()->toDateTimeString());
    }

    public function test_month_and_year_boundaries(): void
    {
        $date = Carbon::parse('2026-03-15 14:30:45');

        $this->assertSame('2026-03-01 00:00:00', $date->startOfMonth()->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', $date->endOfMonth()->toDateTimeString());
        $this->assertSame('2026-01-01 00:00:00', $date->startOfYear()->toDateTimeString());
        $this->assertSame('2026-12-31 23:59:59', $date->endOfYear()->toDateTimeString());
    }

    /** 2026-03-15 is a Sunday; the week starts on Monday by default. */
    public function test_week_boundaries(): void
    {
        $date = Carbon::parse('2026-03-15');

        $this->assertSame('2026-03-09', $date->startOfWeek()->toDateString());
        $this->assertSame('2026-03-15', $date->endOfWeek()->toDateString());
        $this->assertSame('2026-03-15', $date->startOfWeek(7)->toDateString());
    }

    public function test_quarter_boundaries(): void
    {
        $date = Carbon::parse('2026-05-15');

        $this->assertSame(2, $date->quarter);
        $this->assertSame('2026-04-01', $date->startOfQuarter()->toDateString());
        $this->assertSame('2026-06-30', $date->endOfQuarter()->toDateString());
    }

    // ─── Comparison ───────────────────────────────────────

    public function test_comparisons(): void
    {
        $early = Carbon::parse('2026-01-01');
        $late = Carbon::parse('2026-12-31');

        $this->assertTrue($early->lt($late));
        $this->assertTrue($late->gt($early));
        $this->assertTrue($early->lte($early->copy()));
        $this->assertTrue($early->eq($early->copy()));
        $this->assertTrue($early->ne($late));
        $this->assertSame('2026-01-01', $early->min($late)->toDateString());
        $this->assertSame('2026-12-31', $early->max($late)->toDateString());
    }

    public function test_between(): void
    {
        $date = Carbon::parse('2026-06-15');
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-12-31');

        $this->assertTrue($date->between($from, $to));
        $this->assertFalse($from->between($date, $to));
        $this->assertTrue($from->between($from, $to));
        $this->assertFalse($from->between($from, $to, false));
    }

    public function test_relative_predicates(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->assertTrue(Carbon::parse('2020-01-01')->isPast());
        $this->assertTrue(Carbon::parse('2030-01-01')->isFuture());
        $this->assertTrue(Carbon::parse('2026-06-15 08:00:00')->isToday());
        $this->assertTrue(Carbon::parse('2026-06-14 08:00:00')->isYesterday());
        $this->assertTrue(Carbon::parse('2026-06-16 08:00:00')->isTomorrow());
    }

    public function test_same_day_month_year(): void
    {
        $date = Carbon::parse('2026-06-15 09:00:00');

        $this->assertTrue($date->isSameDay(Carbon::parse('2026-06-15 23:00:00')));
        $this->assertTrue($date->isSameMonth(Carbon::parse('2026-06-01')));
        $this->assertTrue($date->isSameYear(Carbon::parse('2026-12-31')));
        $this->assertFalse($date->isSameDay(Carbon::parse('2026-06-16')));
    }

    /** 2026-03-14 is a Saturday, 2026-03-16 a Monday. */
    public function test_weekend_and_weekday(): void
    {
        $this->assertTrue(Carbon::parse('2026-03-14')->isWeekend());
        $this->assertFalse(Carbon::parse('2026-03-16')->isWeekend());
        $this->assertTrue(Carbon::parse('2026-03-16')->isWeekday());
    }

    // ─── Differences ──────────────────────────────────────

    public function test_diff_units(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00');
        $to = Carbon::parse('2026-01-03 06:30:00');

        $this->assertSame(2, $from->diffInDays($to));
        $this->assertSame(54, $from->diffInHours($to));
        $this->assertSame(3270, $from->diffInMinutes($to));
        $this->assertSame(196200, $from->diffInSeconds($to));
    }

    public function test_diff_in_months_and_years(): void
    {
        $from = Carbon::parse('2024-01-15');
        $to = Carbon::parse('2026-04-15');

        $this->assertSame(27, $from->diffInMonths($to));
        $this->assertSame(2, $from->diffInYears($to));
    }

    public function test_diff_sign_when_not_absolute(): void
    {
        $from = Carbon::parse('2026-01-10');
        $to = Carbon::parse('2026-01-01');

        $this->assertSame(9, $from->diffInDays($to));
        $this->assertSame(-9, $from->diffInDays($to, false));
    }

    // ─── diffForHumans ────────────────────────────────────

    public function test_diff_for_humans_past_and_future(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->assertSame('3 days ago', Carbon::parse('2026-06-12 12:00:00')->diffForHumans());
        $this->assertSame('in 3 days', Carbon::parse('2026-06-18 12:00:00')->diffForHumans());
        $this->assertSame('1 hour ago', Carbon::parse('2026-06-15 11:00:00')->diffForHumans());
        $this->assertSame('30 minutes ago', Carbon::parse('2026-06-15 11:30:00')->diffForHumans());
    }

    public function test_diff_for_humans_picks_the_largest_unit(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->assertSame('2 years ago', Carbon::parse('2024-05-01 12:00:00')->diffForHumans());
        $this->assertSame('2 weeks ago', Carbon::parse('2026-06-01 12:00:00')->diffForHumans());
    }

    public function test_diff_for_humans_singular(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->assertSame('1 day ago', Carbon::parse('2026-06-14 12:00:00')->diffForHumans());
    }

    public function test_diff_for_humans_absolute_drops_the_suffix(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->assertSame('3 days', Carbon::parse('2026-06-12 12:00:00')->diffForHumans(null, true));
    }

    public function test_diff_for_humans_against_another_date(): void
    {
        $earlier = Carbon::parse('2026-06-12 12:00:00');
        $later = Carbon::parse('2026-06-15 12:00:00');

        $this->assertSame('3 days before', $earlier->diffForHumans($later));
        $this->assertSame('3 days after', $later->diffForHumans($earlier));
    }

    // ─── Formatting ───────────────────────────────────────

    public function test_format_helpers(): void
    {
        $date = Carbon::parse('2026-03-15 14:30:45');

        $this->assertSame('2026-03-15', $date->toDateString());
        $this->assertSame('14:30:45', $date->toTimeString());
        $this->assertSame('2026-03-15 14:30:45', $date->toDateTimeString());
        $this->assertSame('Mar 15, 2026', $date->toFormattedDateString());
        $this->assertSame('2026-03-15 14:30:45', (string) $date);
        $this->assertStringStartsWith('2026-03-15T14:30:45', $date->toIso8601String());
    }

    public function test_json_serialisation(): void
    {
        $date = Carbon::parse('2026-03-15 14:30:45');

        $this->assertStringContainsString('2026-03-15T14:30:45', json_encode(['at' => $date]));
    }

    public function test_to_array(): void
    {
        $array = Carbon::parse('2026-03-15 14:30:45')->toArray();

        $this->assertSame(2026, $array['year']);
        $this->assertSame(3, $array['month']);
        $this->assertSame(15, $array['day']);
    }

    // ─── Timezones ────────────────────────────────────────

    public function test_timezone_handling(): void
    {
        $utc = Carbon::parse('2026-03-15 12:00:00', 'UTC');
        $tokyo = $utc->setTimezone('Asia/Tokyo');

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('Asia/Tokyo', $tokyo->getTimezone()->getName());
        $this->assertSame($utc->getTimestamp(), $tokyo->getTimestamp());
        $this->assertSame('2026-03-15 21:00:00', $tokyo->toDateTimeString());
    }
}
