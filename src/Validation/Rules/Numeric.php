<?php

namespace Nitro\Validation\Rules;

/**
 * Numeric Rule
 * 
 * Validates that a value is numeric (int, float, or numeric string like "123.45")
 */
class Numeric extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (is_int($this->value) || is_float($this->value)) {
            return true;
        }

        if (!is_string($this->value)) {
            return false;
        }

        // Strict: optional sign, digits, optional decimal. Rejects scientific
        // notation ("5e3"), hex/octal strings, leading whitespace, and any
        // trailing junk that is_numeric() would otherwise accept silently.
        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be numeric.');
    }
}