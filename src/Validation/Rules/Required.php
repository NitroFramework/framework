<?php

namespace Nitro\Validation\Rules;

/**
 * Required Rule
 * 
 * Validates that a field is not empty
 * Treats '0' and '0.0' as valid (not empty)
 */
class Required extends AbstractRule
{
    public function passes(): bool
    {
        // '0' and '0.0' are valid values
        if ($this->value === '0' || $this->value === 0 || $this->value === 0.0) {
            return true;
        }

        return !$this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} field is required.');
    }
}