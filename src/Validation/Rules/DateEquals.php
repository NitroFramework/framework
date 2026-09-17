<?php

namespace Nitro\Validation\Rules;

/**
 * The date equals the given date, or another field's date.
 *
 * Usage: 'starts_on' => 'date_equals:2026-01-01'
 */
class DateEquals extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $other = $this->comparisonTimestamp();
        $value = $this->timestampOf($this->value);

        return $other !== null && $value !== null && $value === $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a date equal to ' . $this->getParameter(0) . '.');
    }
}
