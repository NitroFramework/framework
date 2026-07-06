<?php

namespace Nitro\Validation\Rules;

/**
 * Max Rule
 * 
 * Validates maximum value (for numbers) or maximum length (for strings)
 * Intelligently detects if value is numeric or string
 */
class Max extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $maxValue = (float)$this->getParameter(0);

        // Check if value is a string first
        if (is_string($this->value)) {
            return strlen($this->value) <= $maxValue;
        }

        // Otherwise check as numeric
        if (is_numeric($this->value)) {
            return (float)$this->value <= $maxValue;
        }

        return true;
    }

    public function message(): string
    {
        $max = $this->getParameter(0);
        
        if (is_string($this->value)) {
            return $this->replaceMessage("The {attribute} may not exceed {$max} characters.");
        }

        return $this->replaceMessage("The {attribute} may not be greater than {$max}.");
    }
}