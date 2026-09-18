<?php

namespace Nitro\Scheduling;

use DateTimeInterface;
use DateTimeZone;

/**
 * The vocabulary for saying when a scheduled task runs.
 *
 * Every method writes into the five cron fields the event holds, so a task
 * described as `->dailyAt('02:30')` and one described as `->cron('30 2 * * *')`
 * are the same task by the time it is due.
 */
trait ManagesFrequencies
{
    /**
     * Set the task's cron expression outright.
     */
    public function cron(string $expression): static
    {
        $this->fields = preg_split('/\s+/', trim($expression));

        return $this;
    }

    /**
     * Run the task in a given timezone rather than the application's.
     */
    public function timezone(DateTimeZone|string $timezone): static
    {
        $this->timezone = $timezone instanceof DateTimeZone
            ? $timezone->getName()
            : $timezone;

        return $this;
    }

    // ─── Minutes ──────────────────────────────────────────

    /** Run the task every minute. */
    public function everyMinute(): static
    {
        return $this->spliceIntoPosition(1, '*');
    }

    /** Run the task every two minutes. */
    public function everyTwoMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/2');
    }

    /** Run the task every three minutes. */
    public function everyThreeMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/3');
    }

    /** Run the task every four minutes. */
    public function everyFourMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/4');
    }

    /** Run the task every five minutes. */
    public function everyFiveMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/5');
    }

    /** Run the task every ten minutes. */
    public function everyTenMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/10');
    }

    /** Run the task every fifteen minutes. */
    public function everyFifteenMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/15');
    }

    /** Run the task every thirty minutes. */
    public function everyThirtyMinutes(): static
    {
        return $this->spliceIntoPosition(1, '0,30');
    }

    // ─── Hours ────────────────────────────────────────────

    /** Run the task on the hour. */
    public function hourly(): static
    {
        return $this->spliceIntoPosition(1, '0');
    }

    /**
     * Run the task hourly at the given minute past.
     *
     * @param int|array<int, int> $offset
     */
    public function hourlyAt(int|array $offset): static
    {
        return $this->hourBasedSchedule($offset, '*');
    }

    /** Run the task every odd-numbered hour. */
    public function everyOddHour(int|array $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '1-23/2');
    }

    /** Run the task every two hours. */
    public function everyTwoHours(int|array $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/2');
    }

    /** Run the task every three hours. */
    public function everyThreeHours(int|array $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/3');
    }

    /** Run the task every four hours. */
    public function everyFourHours(int|array $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/4');
    }

    /** Run the task every six hours. */
    public function everySixHours(int|array $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/6');
    }

    // ─── Days ─────────────────────────────────────────────

    /** Run the task at midnight. */
    public function daily(): static
    {
        return $this->hourBasedSchedule(0, 0);
    }

    /**
     * Run the task once a day at the given time.
     */
    public function dailyAt(string $time): static
    {
        return $this->at($time);
    }

    /**
     * Run the task once a day at the given time.
     */
    public function at(string $time): static
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $this->spliceIntoPosition(2, (int) $hour)
            ->spliceIntoPosition(1, (int) $minute);
    }

    /** Run the task twice a day. */
    public function twiceDaily(int $first = 1, int $second = 13): static
    {
        return $this->twiceDailyAt($first, $second, 0);
    }

    /** Run the task twice a day, at the given minute past each hour. */
    public function twiceDailyAt(int $first = 1, int $second = 13, int $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, $first . ',' . $second);
    }

    /** Run the task only on weekdays. */
    public function weekdays(): static
    {
        return $this->days('1-5');
    }

    /** Run the task only at weekends. */
    public function weekends(): static
    {
        return $this->days('0,6');
    }

    /** Run the task only on Mondays. */
    public function mondays(): static
    {
        return $this->days(1);
    }

    /** Run the task only on Tuesdays. */
    public function tuesdays(): static
    {
        return $this->days(2);
    }

    /** Run the task only on Wednesdays. */
    public function wednesdays(): static
    {
        return $this->days(3);
    }

    /** Run the task only on Thursdays. */
    public function thursdays(): static
    {
        return $this->days(4);
    }

    /** Run the task only on Fridays. */
    public function fridays(): static
    {
        return $this->days(5);
    }

    /** Run the task only on Saturdays. */
    public function saturdays(): static
    {
        return $this->days(6);
    }

    /** Run the task only on Sundays. */
    public function sundays(): static
    {
        return $this->days(0);
    }

    /**
     * Restrict the task to the given days of the week.
     *
     * @param int|string|array<int, int|string> $days
     */
    public function days(int|string|array $days): static
    {
        $days = is_array($days) ? $days : func_get_args();

        return $this->spliceIntoPosition(5, implode(',', $days));
    }

    // ─── Weeks, months, years ─────────────────────────────

    /** Run the task weekly, at midnight on Sunday. */
    public function weekly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(5, 0);
    }

    /**
     * Run the task weekly on the given day and time.
     *
     * @param int|string|array<int, int|string> $dayOfWeek
     */
    public function weeklyOn(int|string|array $dayOfWeek, string $time = '0:0'): static
    {
        return $this->at($time)->days($dayOfWeek);
    }

    /** Run the task on the first of the month, at midnight. */
    public function monthly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1);
    }

    /** Run the task monthly on the given day and time. */
    public function monthlyOn(int $dayOfMonth = 1, string $time = '0:0'): static
    {
        return $this->at($time)->spliceIntoPosition(3, $dayOfMonth);
    }

    /** Run the task twice a month, on the given days. */
    public function twiceMonthly(int $first = 1, int $second = 16, string $time = '0:0'): static
    {
        return $this->at($time)->spliceIntoPosition(3, $first . ',' . $second);
    }

    /** Run the task on the last day of the month. */
    public function lastDayOfMonth(string $time = '0:0'): static
    {
        return $this->at($time)->spliceIntoPosition(3, (int) date('t'));
    }

    /** Run the task on the first day of each quarter. */
    public function quarterly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, '1-12/3');
    }

    /** Run the task quarterly on the given day and time. */
    public function quarterlyOn(int $dayOfQuarter = 1, string $time = '0:0'): static
    {
        return $this->at($time)
            ->spliceIntoPosition(3, $dayOfQuarter)
            ->spliceIntoPosition(4, '1-12/3');
    }

    /** Run the task on the first day of the year. */
    public function yearly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, 1);
    }

    /** Run the task yearly on the given month, day and time. */
    public function yearlyOn(int $month = 1, int|string $dayOfMonth = 1, string $time = '0:0'): static
    {
        return $this->at($time)
            ->spliceIntoPosition(3, $dayOfMonth)
            ->spliceIntoPosition(4, $month);
    }

    // ─── Windows ──────────────────────────────────────────

    /**
     * Run the task only between two times of day.
     */
    public function between(string $startTime, string $endTime): static
    {
        return $this->when($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * Run the task except between two times of day.
     */
    public function unlessBetween(string $startTime, string $endTime): static
    {
        return $this->skip($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * A test for whether now falls inside a time window.
     *
     * A window whose end is before its start spans midnight, so it is treated
     * as two windows rather than an empty one.
     */
    protected function inTimeInterval(string $startTime, string $endTime): callable
    {
        return function () use ($startTime, $endTime): bool {
            $now = $this->currentTime();
            $start = $this->minutesFromMidnight($startTime);
            $end = $this->minutesFromMidnight($endTime);

            return $start <= $end
                ? $now >= $start && $now <= $end
                : $now >= $start || $now <= $end;
        };
    }

    /**
     * Minutes since midnight, in the event's timezone.
     */
    protected function currentTime(): int
    {
        $now = new \DateTimeImmutable('now', new DateTimeZone($this->timezone ?? date_default_timezone_get()));

        return ((int) $now->format('G')) * 60 + (int) $now->format('i');
    }

    /**
     * Parse an `H:i` time into minutes since midnight.
     */
    protected function minutesFromMidnight(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }

    // ─── Internals ────────────────────────────────────────

    /**
     * Set the hour and minute fields together.
     *
     * @param int|array<int, int> $offset Minute past the hour, or several.
     */
    protected function hourBasedSchedule(int|string|array $offset, int|string $hours): static
    {
        $offset = is_array($offset) ? implode(',', $offset) : $offset;

        return $this->spliceIntoPosition(1, $offset)
            ->spliceIntoPosition(2, $hours);
    }

    /**
     * Write a value into one of the five cron fields, counting from 1.
     */
    protected function spliceIntoPosition(int $position, int|string $value): static
    {
        $this->fields[$position - 1] = (string) $value;

        return $this;
    }
}
