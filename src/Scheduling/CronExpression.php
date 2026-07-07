<?php

namespace Nitro\Scheduling;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A minimal 5-field cron matcher (minute hour day-of-month month day-of-week).
 * Each field supports '*', numbers, lists (1,2), ranges (1-5) and steps (*\/5).
 */
class CronExpression
{
    public function __construct(
        protected string $expression
    ) {}

    public function isDue(DateTimeInterface $date): bool
    {
        $fields = preg_split('/\s+/', trim($this->expression));
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression: {$this->expression}");
        }

        return $this->fieldMatches($fields[0], (int) $date->format('i'), 0, 59)   // minute
            && $this->fieldMatches($fields[1], (int) $date->format('G'), 0, 23)   // hour
            && $this->fieldMatches($fields[2], (int) $date->format('j'), 1, 31)   // day of month
            && $this->fieldMatches($fields[3], (int) $date->format('n'), 1, 12)   // month
            && $this->fieldMatches($fields[4], (int) $date->format('w'), 0, 6);   // day of week (0=Sun)
    }

    protected function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($this->partMatches($part, $value, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    protected function partMatches(string $part, int $value, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepPart] = explode('/', $part, 2);
            $step = max(1, (int) $stepPart);
        }

        if ($part === '*') {
            [$rangeMin, $rangeMax] = [$min, $max];
        } elseif (str_contains($part, '-')) {
            [$a, $b] = explode('-', $part, 2);
            [$rangeMin, $rangeMax] = [(int) $a, (int) $b];
        } else {
            return $value === (int) $part;
        }

        if ($value < $rangeMin || $value > $rangeMax) {
            return false;
        }

        return (($value - $rangeMin) % $step) === 0;
    }
}
