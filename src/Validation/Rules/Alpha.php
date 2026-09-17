<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains only letters.
 *
 * Usage: 'name' => 'alpha'
 */
class Alpha extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[\pL\pM]+$/u', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may only contain letters.');
    }
}
