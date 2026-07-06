<?php

namespace Nitro\Validation\Rules;

/**
 * Integer Rule
 * 
 * Validates that a value is an integer (actual int or integer-like string)
 */
class Integer extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        // Accept actual integers
        if (is_int($this->value)) {
            return true;
        }

        // Accept integer-like strings
        return ctype_digit((string)$this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be an integer.');
    }
}