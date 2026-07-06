<?php

namespace Nitro\Validation\Rules;

/**
 * Email Rule
 * 
 * Validates that a value is a valid email address
 */
class Email extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return filter_var($this->value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid email address.');
    }
}