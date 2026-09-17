<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a negative: no, off, 0, "0" or false.
 *
 * Usage: 'marketing' => 'declined'
 */
class Declined extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return in_array($this->value, ['no', 'off', '0', 0, false, 'false'], true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be declined.');
    }
}
