<?php

namespace Nitro\Validation\Rules;

/**
 * Regex Rule
 * 
 * Validates that a value matches a regular expression pattern
 * 
 * Usage: regex:/^[0-9\-\+\(\)\s]{7,20}$/
 */
class Regex extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $pattern = $this->getParameter(0);
        
        if (!$pattern) {
            return false;
        }

        try {
            return preg_match((string)$pattern, (string)$this->value) === 1;
        } catch (\Throwable $e) {
            // Invalid regex pattern
            return false;
        }
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} format is invalid.');
    }
}