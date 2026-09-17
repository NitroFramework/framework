<?php

namespace Nitro\Validation\Rules;

/**
 * The date is at or before the given date, or another field's date.
 *
 * Usage: 'start' => 'before_or_equal:end'
 */
class BeforeOrEqual extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $other = $this->comparisonTimestamp();
        $value = $this->timestampOf($this->value);

        return $other !== null && $value !== null && $value <= $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a date before or equal to ' . $this->getParameter(0) . '.');
    }
}
