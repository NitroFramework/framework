<?php

namespace Nitro\Validation\Rules;

/**
 * The date is at or after the given date, or another field's date.
 *
 * Usage: 'end' => 'after_or_equal:start'
 */
class AfterOrEqual extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $other = $this->comparisonTimestamp();
        $value = $this->timestampOf($this->value);

        return $other !== null && $value !== null && $value >= $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a date after or equal to ' . $this->getParameter(0) . '.');
    }
}
