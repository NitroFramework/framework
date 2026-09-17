<?php

namespace Nitro\Validation\Rules;

/**
 * Submitting this field forbids the named fields from being filled.
 *
 * Usage: 'anonymous' => 'prohibits:name,email'
 */
class Prohibits extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        foreach ($this->parameters as $field) {
            if ($this->otherFilled((string) $field)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} prohibits ' . implode(' / ', $this->parameters) . ' from being present.');
    }
}
