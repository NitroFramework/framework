<?php

namespace Nitro\Validation\Rules;

/**
 * The value is already lower case.
 *
 * Usage: 'email' => 'lowercase'
 */
class Lowercase extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && $this->value === mb_strtolower($this->value, 'UTF-8');
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be lowercase.');
    }
}
