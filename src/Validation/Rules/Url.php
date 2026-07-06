<?php

namespace Nitro\Validation\Rules;

/**
 * URL Rule
 * 
 * Validates that a value is a valid URL
 */
class Url extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return filter_var($this->value, FILTER_VALIDATE_URL) !== false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid URL.');
    }
}