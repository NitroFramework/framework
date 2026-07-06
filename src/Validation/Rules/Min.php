<?php

namespace Nitro\Validation\Rules;

/**
 * Min Rule
 * 
 * Validates minimum value (for numbers) or minimum length (for strings)
 * Intelligently detects if value is numeric or string
 */
class Min extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $minValue = (float)$this->getParameter(0);

        // Check if value is a string first
        if (is_string($this->value)) {
            return strlen($this->value) >= $minValue;
        }

        // Otherwise check as numeric
        if (is_numeric($this->value)) {
            return (float)$this->value >= $minValue;
        }

        return true;
    }

    public function message(): string
    {
        $min = $this->getParameter(0);

        if (is_string($this->value)) {
            return $this->replaceMessage("The {attribute} must be at least {$min} characters.");
        }

        return $this->replaceMessage("The {attribute} must be at least {$min}.");
    }
}