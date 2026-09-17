<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains only 7-bit ASCII characters.
 *
 * Usage: 'username' => 'ascii'
 */
class Ascii extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[\x00-\x7F]*$/', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may only contain single-byte characters.');
    }
}
