<?php

namespace Nitro\Support;

use DateTimeZone;

/**
 * Builds dates, for code that would rather ask an object than a class.
 *
 *     $factory->now();
 *     $factory->parse('2026-01-01')->addDays(7);
 */
class DateFactory
{
    public function now(DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::now($timezone);
    }

    public function today(DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::today($timezone);
    }

    public function tomorrow(DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::tomorrow($timezone);
    }

    public function yesterday(DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::yesterday($timezone);
    }

    public function parse(string $time, DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::parse($time, $timezone);
    }

    public function createFromTimestamp(int $timestamp, DateTimeZone|string|null $timezone = null): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, $timezone);
    }

    /** Freeze the clock, or let it run again with null. */
    public function setTestNow(\DateTimeInterface|string|null $now = null): void
    {
        Carbon::setTestNow($now);
    }
}
