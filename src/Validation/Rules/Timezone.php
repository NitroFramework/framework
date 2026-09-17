<?php

namespace Nitro\Validation\Rules;

/**
 * The value names a timezone PHP recognises.
 *
 * Usage: 'tz' => 'timezone'
 */
class Timezone extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value)
            && in_array($this->value, timezone_identifiers_list(), true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid timezone.');
    }
}
