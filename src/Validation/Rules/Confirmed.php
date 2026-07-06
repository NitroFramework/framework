<?php

namespace Nitro\Validation\Rules;

/**
 * Confirmed Rule
 * 
 * Validates that a field matches another field (typically for password confirmation)
 * 
 * Usage: password => 'required|confirmed'
 * Expects: password_confirmation field in data
 */
class Confirmed extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $confirmationField = "{$this->attribute}_confirmation";
        $confirmationValue = $this->data[$confirmationField] ?? null;

        return $this->value === $confirmationValue;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} confirmation does not match.');
    }
}