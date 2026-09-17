<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a ULID: 26 characters of Crockford base32.
 *
 * Usage: 'id' => 'ulid'
 */
class Ulid extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid ULID.');
    }
}
