<?php

namespace Nitro\Validation\Rules;

/**
 * String Rule
 * 
 * Validates that a value is a string
 */
class StringRule extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a string.');
    }
}