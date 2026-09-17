<?php

namespace Nitro\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Stringable;

/**
 * The date and time type the framework returns from now(), today() and datetime
 * casts.
 *
 * Immutable: every method that would change the date returns a new instance and
 * leaves the receiver untouched.
 *
 *   now()->addDays(3)->startOfDay()->toDateTimeString()
 *   $post->published_at->diffForHumans()
 *
 * Arithmetic and parsing are delegated to PHP's own date handling, so month-end
 * rollover, leap years and DST transitions behave as they do in DateTimeImmutable.
 */
class Carbon extends DateTimeImmutable implements JsonSerializable, Stringable
{
    /** A fixed "now" for tests, or null to read the clock. */
    private static ?DateTimeImmutable $testNow = null;

    // ─── Construction ─────────────────────────────────────────────────────

    public static function now(DateTimeZone|string|null $timezone = null): static
    {
        if (self::$testNow !== null) {
            $instance = static::instance(self::$testNow);

            return $timezone === null ? $instance : $instance->setTimezone(self::timezone($timezone));
        }

        return new static('now', self::timezone($timezone));
    }

    public static function today(DateTimeZone|string|null $timezone = null): static
    {
        return static::now($timezone)->startOfDay();
    }

    public static function tomorrow(DateTimeZone|string|null $timezone = null): static
    {
        return static::today($timezone)->addDay();
    }

    public static function yesterday(DateTimeZone|string|null $timezone = null): static
    {
        return static::today($timezone)->subDay();
    }

    /** Parse any string PHP's date parser accepts. */
    public static function parse(string $time = 'now', DateTimeZone|string|null $timezone = null): static
    {
        if ($time === 'now' || $time === '') {
            return static::now($timezone);
        }

        if (self::$testNow !== null && self::isRelative($time)) {
            return static::now($timezone)->modify($time);
        }

        return new static($time, self::timezone($timezone));
    }

    public static function create(
        int $year = 0,
        int $month = 1,
        int $day = 1,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        DateTimeZone|string|null $timezone = null
    ): static {
        return (new static('now', self::timezone($timezone)))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second);
    }

    public static function createFromTimestamp(int $timestamp, DateTimeZone|string|null $timezone = null): static
    {
        $instance = new static('@' . $timestamp);
        $zone = self::timezone($timezone) ?? (new static('now'))->getTimezone();

        return $instance->setTimezone($zone);
    }

    public static function createFromTimestampMs(int $milliseconds, DateTimeZone|string|null $timezone = null): static
    {
        return static::createFromTimestamp(intdiv($milliseconds, 1000), $timezone);
    }

    /** Build one from any other date object. */
    public static function instance(DateTimeInterface $date): static
    {
        return new static($date->format('Y-m-d H:i:s.u'), $date->getTimezone());
    }

    /**
     * Build one from anything date-like, or null when there is nothing to build
     * from. Numeric input is read as a Unix timestamp.
     */
    public static function make(mixed $value, DateTimeZone|string|null $timezone = null): ?static
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return static::instance($value);
        }

        if (is_numeric($value)) {
            return static::createFromTimestamp((int) $value, $timezone);
        }

        return static::parse((string) $value, $timezone);
    }

    // ─── Test clock ───────────────────────────────────────────────────────

    /** Freeze the clock, or pass null to release it. */
    public static function setTestNow(mixed $testNow = null): void
    {
        if ($testNow === null) {
            self::$testNow = null;

            return;
        }

        $instance = $testNow instanceof DateTimeInterface
            ? $testNow
            : new static((string) $testNow);

        self::$testNow = new DateTimeImmutable(
            $instance->format('Y-m-d H:i:s.u'),
            $instance->getTimezone()
        );
    }

    public static function hasTestNow(): bool
    {
        return self::$testNow !== null;
    }

    public static function getTestNow(): ?DateTimeImmutable
    {
        return self::$testNow;
    }

    // ─── Components ───────────────────────────────────────────────────────

    /**
     * Read a date component by name: year, month, day, hour, minute, second,
     * micro, dayOfWeek, dayOfWeekIso, dayOfYear, weekOfYear, daysInMonth,
     * quarter, timestamp, englishDayOfWeek, englishMonth.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'year'             => (int) $this->format('Y'),
            'month'            => (int) $this->format('n'),
            'day'              => (int) $this->format('j'),
            'hour'             => (int) $this->format('G'),
            'minute'           => (int) $this->format('i'),
            'second'           => (int) $this->format('s'),
            'micro',
            'microsecond'      => (int) $this->format('u'),
            'dayOfWeek'        => (int) $this->format('w'),
            'dayOfWeekIso'     => (int) $this->format('N'),
            'dayOfYear'        => (int) $this->format('z'),
            'weekOfYear'       => (int) $this->format('W'),
            'daysInMonth'      => (int) $this->format('t'),
            'quarter'          => (int) ceil((int) $this->format('n') / 3),
            'timestamp'        => $this->getTimestamp(),
            'englishDayOfWeek' => $this->format('l'),
            'englishMonth'     => $this->format('F'),
            'timezoneName',
            'tzName'           => $this->getTimezone()->getName(),
            default            => null,
        };
    }

    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }

    // ─── Formatting ───────────────────────────────────────────────────────

    public function toDateString(): string
    {
        return $this->format('Y-m-d');
    }

    public function toTimeString(): string
    {
        return $this->format('H:i:s');
    }

    public function toDateTimeString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }

    public function toDayDateTimeString(): string
    {
        return $this->format('D, M j, Y g:i A');
    }

    public function toIso8601String(): string
    {
        return $this->format('c');
    }

    public function toAtomString(): string
    {
        return $this->format(DateTimeInterface::ATOM);
    }

    public function toRfc3339String(): string
    {
        return $this->format(DateTimeInterface::RFC3339);
    }

    public function toFormattedDateString(): string
    {
        return $this->format('M j, Y');
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'year'      => $this->year,
            'month'     => $this->month,
            'day'       => $this->day,
            'hour'      => $this->hour,
            'minute'    => $this->minute,
            'second'    => $this->second,
            'timestamp' => $this->getTimestamp(),
            'timezone'  => $this->getTimezone()->getName(),
        ];
    }

    public function jsonSerialize(): string
    {
        return $this->toIso8601String();
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    // ─── Arithmetic ───────────────────────────────────────────────────────

    public function addSeconds(int $value): static { return $this->shift($value, 'second'); }
    public function subSeconds(int $value): static { return $this->shift(-$value, 'second'); }
    public function addSecond(int $value = 1): static { return $this->shift($value, 'second'); }
    public function subSecond(int $value = 1): static { return $this->shift(-$value, 'second'); }

    public function addMinutes(int $value): static { return $this->shift($value, 'minute'); }
    public function subMinutes(int $value): static { return $this->shift(-$value, 'minute'); }
    public function addMinute(int $value = 1): static { return $this->shift($value, 'minute'); }
    public function subMinute(int $value = 1): static { return $this->shift(-$value, 'minute'); }

    public function addHours(int $value): static { return $this->shift($value, 'hour'); }
    public function subHours(int $value): static { return $this->shift(-$value, 'hour'); }
    public function addHour(int $value = 1): static { return $this->shift($value, 'hour'); }
    public function subHour(int $value = 1): static { return $this->shift(-$value, 'hour'); }

    public function addDays(int $value): static { return $this->shift($value, 'day'); }
    public function subDays(int $value): static { return $this->shift(-$value, 'day'); }
    public function addDay(int $value = 1): static { return $this->shift($value, 'day'); }
    public function subDay(int $value = 1): static { return $this->shift(-$value, 'day'); }

    public function addWeeks(int $value): static { return $this->shift($value, 'week'); }
    public function subWeeks(int $value): static { return $this->shift(-$value, 'week'); }
    public function addWeek(int $value = 1): static { return $this->shift($value, 'week'); }
    public function subWeek(int $value = 1): static { return $this->shift(-$value, 'week'); }

    public function addMonths(int $value): static { return $this->shift($value, 'month'); }
    public function subMonths(int $value): static { return $this->shift(-$value, 'month'); }
    public function addMonth(int $value = 1): static { return $this->shift($value, 'month'); }
    public function subMonth(int $value = 1): static { return $this->shift(-$value, 'month'); }

    public function addYears(int $value): static { return $this->shift($value, 'year'); }
    public function subYears(int $value): static { return $this->shift(-$value, 'year'); }
    public function addYear(int $value = 1): static { return $this->shift($value, 'year'); }
    public function subYear(int $value = 1): static { return $this->shift(-$value, 'year'); }

    /**
     * Add months without rolling past the end of the target month: 31 January
     * plus one month is 28 or 29 February rather than 2 or 3 March.
     */
    public function addMonthsNoOverflow(int $value): static
    {
        $day = $this->day;
        $shifted = $this->setDate($this->year, $this->month, 1)->shift($value, 'month');

        return $shifted->setDate(
            $shifted->year,
            $shifted->month,
            min($day, $shifted->daysInMonth)
        )->setTime($this->hour, $this->minute, $this->second);
    }

    public function subMonthsNoOverflow(int $value): static
    {
        return $this->addMonthsNoOverflow(-$value);
    }

    private function shift(int $value, string $unit): static
    {
        if ($value === 0) {
            return $this;
        }

        return $this->modify(sprintf('%+d %s', $value, $unit));
    }

    // ─── Boundaries ───────────────────────────────────────────────────────

    public function startOfSecond(): static
    {
        return $this->setTime($this->hour, $this->minute, $this->second, 0);
    }

    public function endOfSecond(): static
    {
        return $this->setTime($this->hour, $this->minute, $this->second, 999999);
    }

    public function startOfMinute(): static
    {
        return $this->setTime($this->hour, $this->minute, 0, 0);
    }

    public function endOfMinute(): static
    {
        return $this->setTime($this->hour, $this->minute, 59, 999999);
    }

    public function startOfHour(): static
    {
        return $this->setTime($this->hour, 0, 0, 0);
    }

    public function endOfHour(): static
    {
        return $this->setTime($this->hour, 59, 59, 999999);
    }

    public function startOfDay(): static
    {
        return $this->setTime(0, 0, 0, 0);
    }

    public function endOfDay(): static
    {
        return $this->setTime(23, 59, 59, 999999);
    }

    /** @param int $weekStartsAt 1 for Monday through 7 for Sunday. */
    public function startOfWeek(int $weekStartsAt = 1): static
    {
        $offset = ($this->dayOfWeekIso - $weekStartsAt + 7) % 7;

        return $this->subDays($offset)->startOfDay();
    }

    public function endOfWeek(int $weekStartsAt = 1): static
    {
        return $this->startOfWeek($weekStartsAt)->addDays(6)->endOfDay();
    }

    public function startOfMonth(): static
    {
        return $this->setDate($this->year, $this->month, 1)->startOfDay();
    }

    public function endOfMonth(): static
    {
        return $this->setDate($this->year, $this->month, $this->daysInMonth)->endOfDay();
    }

    public function startOfYear(): static
    {
        return $this->setDate($this->year, 1, 1)->startOfDay();
    }

    public function endOfYear(): static
    {
        return $this->setDate($this->year, 12, 31)->endOfDay();
    }

    public function startOfQuarter(): static
    {
        return $this->setDate($this->year, (($this->quarter - 1) * 3) + 1, 1)->startOfDay();
    }

    public function endOfQuarter(): static
    {
        return $this->startOfQuarter()->addMonths(3)->subDay()->endOfDay();
    }

    // ─── Comparison ───────────────────────────────────────────────────────

    public function equalTo(DateTimeInterface $other): bool { return $this == $other; }
    public function notEqualTo(DateTimeInterface $other): bool { return $this != $other; }
    public function greaterThan(DateTimeInterface $other): bool { return $this > $other; }
    public function greaterThanOrEqualTo(DateTimeInterface $other): bool { return $this >= $other; }
    public function lessThan(DateTimeInterface $other): bool { return $this < $other; }
    public function lessThanOrEqualTo(DateTimeInterface $other): bool { return $this <= $other; }

    public function eq(DateTimeInterface $other): bool { return $this->equalTo($other); }
    public function ne(DateTimeInterface $other): bool { return $this->notEqualTo($other); }
    public function gt(DateTimeInterface $other): bool { return $this->greaterThan($other); }
    public function gte(DateTimeInterface $other): bool { return $this->greaterThanOrEqualTo($other); }
    public function lt(DateTimeInterface $other): bool { return $this->lessThan($other); }
    public function lte(DateTimeInterface $other): bool { return $this->lessThanOrEqualTo($other); }

    public function between(DateTimeInterface $from, DateTimeInterface $to, bool $inclusive = true): bool
    {
        return $inclusive
            ? ($this >= $from && $this <= $to)
            : ($this > $from && $this < $to);
    }

    public function min(DateTimeInterface $other): static
    {
        return $this <= $other ? $this : static::instance($other);
    }

    public function max(DateTimeInterface $other): static
    {
        return $this >= $other ? $this : static::instance($other);
    }

    public function isPast(): bool
    {
        return $this < static::now($this->getTimezone());
    }

    public function isFuture(): bool
    {
        return $this > static::now($this->getTimezone());
    }

    public function isToday(): bool
    {
        return $this->isSameDay(static::now($this->getTimezone()));
    }

    public function isYesterday(): bool
    {
        return $this->isSameDay(static::yesterday($this->getTimezone()));
    }

    public function isTomorrow(): bool
    {
        return $this->isSameDay(static::tomorrow($this->getTimezone()));
    }

    public function isSameDay(DateTimeInterface $other): bool
    {
        return $this->format('Y-m-d') === $other->format('Y-m-d');
    }

    public function isSameMonth(DateTimeInterface $other): bool
    {
        return $this->format('Y-m') === $other->format('Y-m');
    }

    public function isSameYear(DateTimeInterface $other): bool
    {
        return $this->format('Y') === $other->format('Y');
    }

    public function isWeekend(): bool
    {
        return $this->dayOfWeekIso >= 6;
    }

    public function isWeekday(): bool
    {
        return ! $this->isWeekend();
    }

    public function isLeapYear(): bool
    {
        return $this->format('L') === '1';
    }

    public function isStartOfDay(): bool
    {
        return $this->format('H:i:s') === '00:00:00';
    }

    // ─── Differences ──────────────────────────────────────────────────────

    public function diffInSeconds(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        $difference = ($other ?? static::now($this->getTimezone()))->getTimestamp() - $this->getTimestamp();

        return $absolute ? abs($difference) : $difference;
    }

    public function diffInMinutes(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 60);
    }

    public function diffInHours(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 3600);
    }

    public function diffInDays(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInSeconds($other, $absolute), 86400);
    }

    public function diffInWeeks(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        return intdiv($this->diffInDays($other, $absolute), 7);
    }

    public function diffInMonths(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        $target = $other ?? static::now($this->getTimezone());
        $difference = $this->diff($target);
        $months = ($difference->y * 12) + $difference->m;

        if ($absolute) {
            return $months;
        }

        return $difference->invert === 1 ? -$months : $months;
    }

    public function diffInYears(?DateTimeInterface $other = null, bool $absolute = true): int
    {
        $target = $other ?? static::now($this->getTimezone());
        $difference = $this->diff($target);

        return $absolute || $difference->invert === 0 ? $difference->y : -$difference->y;
    }

    /**
     * The difference in words: "3 days ago", "in 2 months", "1 hour before".
     *
     * With no argument the comparison is against now. $absolute drops the
     * suffix entirely.
     */
    public function diffForHumans(?DateTimeInterface $other = null, bool $absolute = false): string
    {
        $comparedToNow = $other === null;
        $target = $other ?? static::now($this->getTimezone());
        $difference = $this->diff($target);

        $phrase = self::largestUnit($difference);

        if ($absolute) {
            return $phrase;
        }

        // invert is 1 when $target precedes $this, so $this lies in the future.
        $isFuture = $difference->invert === 1;

        if ($comparedToNow) {
            return $isFuture ? 'in ' . $phrase : $phrase . ' ago';
        }

        return $isFuture ? $phrase . ' after' : $phrase . ' before';
    }

    /** The largest non-zero unit of a difference, pluralised. */
    private static function largestUnit(DateInterval $difference): string
    {
        $units = [
            'year'   => $difference->y,
            'month'  => $difference->m,
            'week'   => intdiv($difference->d, 7),
            'day'    => $difference->d % 7,
            'hour'   => $difference->h,
            'minute' => $difference->i,
            'second' => $difference->s,
        ];

        foreach ($units as $unit => $value) {
            if ($value > 0) {
                return $value . ' ' . $unit . ($value === 1 ? '' : 's');
            }
        }

        return '0 seconds';
    }

    // ─── Copying ──────────────────────────────────────────────────────────

    /** Accepts a timezone name as well as a DateTimeZone. */
    public function setTimezone(DateTimeZone|string $timezone): static
    {
        return parent::setTimezone(self::timezone($timezone));
    }

    public function copy(): static
    {
        return clone $this;
    }

    public function clone(): static
    {
        return clone $this;
    }

    // ─── Internals ────────────────────────────────────────────────────────

    private static function timezone(DateTimeZone|string|null $timezone): ?DateTimeZone
    {
        if ($timezone === null) {
            return null;
        }

        return $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($timezone);
    }

    /** Whether a string is relative rather than an absolute date. */
    private static function isRelative(string $time): bool
    {
        return (bool) preg_match(
            '/(ago|next|last|tomorrow|yesterday|today|midnight|noon|[+-]\s*\d)/i',
            $time
        );
    }
}
