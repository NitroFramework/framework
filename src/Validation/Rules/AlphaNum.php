<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains only letters and digits.
 *
 * Usage: 'code' => 'alpha_num'
 */
class AlphaNum extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[\pL\pM\pN]+$/u', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may only contain letters and numbers.');
    }
}
