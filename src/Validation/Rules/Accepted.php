<?php

namespace Nitro\Validation\Rules;

/**
 * The value is an affirmative: yes, on, 1, "1" or true.
 *
 * Usage: 'terms' => 'accepted'
 */
class Accepted extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return in_array($this->value, ['yes', 'on', '1', 1, true, 'true'], true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be accepted.');
    }
}
