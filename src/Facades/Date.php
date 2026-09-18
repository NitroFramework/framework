<?php

namespace Nitro\Facades;

/**
 * Date facade — the framework clock.
 *
 *   Date::now();
 *   Date::parse('2026-01-01')->addDays(7);
 *
 * @method static \Nitro\Support\Carbon now(\DateTimeZone|string|null $timezone = null)
 * @method static \Nitro\Support\Carbon today(\DateTimeZone|string|null $timezone = null)
 * @method static \Nitro\Support\Carbon parse(string $time, \DateTimeZone|string|null $timezone = null)
 * @method static \Nitro\Support\Carbon createFromTimestamp(int $timestamp)
 * @method static void setTestNow(\DateTimeInterface|string|null $now)
 */
class Date extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'date';
    }
}
