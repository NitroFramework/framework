<?php

namespace Nitro\Validation\Rules;

/**
 * Nullable Rule
 * 
 * Special rule that signals to skip other validations if value is empty
 * This rule doesn't actually validate anything - it's a flag
 */
class Nullable extends AbstractRule
{
    public function passes(): bool
    {
        // Nullable always passes - it's handled in the validator
        return true;
    }

    public function message(): string
    {
        return '';
    }
}