<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains every one of the given substrings.
 *
 * Usage: 'body' => 'contains:terms,privacy'
 */
class Contains extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_string($this->value)) {
            return false;
        }

        foreach ($this->parameters as $needle) {
            if (! str_contains($this->value, (string) $needle)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must contain: ' . implode(', ', $this->parameters) . '.');
    }
}
