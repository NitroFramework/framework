<?php

namespace Nitro\Validation\Rules;

/**
 * In Rule
 * 
 * Validates that a value is one of the allowed values
 * 
 * Usage: in:active,inactive,pending
 */
class In extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $allowed = $this->parameters;
        
        return in_array((string)$this->value, $allowed, true);
    }

    public function message(): string
    {
        $allowed = implode(', ', $this->parameters);
        
        return $this->replaceMessage(
            "The {attribute} must be one of: {$allowed}."
        );
    }
}