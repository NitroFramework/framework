<?php

namespace Nitro\Validation\Rules;

/**
 * The value is already upper case.
 *
 * Usage: 'code' => 'uppercase'
 */
class Uppercase extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && $this->value === mb_strtoupper($this->value, 'UTF-8');
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be uppercase.');
    }
}
