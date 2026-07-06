<?php

namespace Nitro\Validation\Rules;

use DateTime;

/**
 * Date Rule
 * 
 * Validates that a value is a valid date in YYYY-MM-DD format
 */
class Date extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        // Check format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$this->value)) {
            return false;
        }

        // Verify it's a real date
        $date = DateTime::createFromFormat('Y-m-d', (string)$this->value);
        return $date && $date->format('Y-m-d') === (string)$this->value;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid date (YYYY-MM-DD).');
    }
}