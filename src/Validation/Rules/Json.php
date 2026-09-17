<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a JSON string.
 *
 * Usage: 'payload' => 'json'
 */
class Json extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_string($this->value)) {
            return false;
        }

        json_decode($this->value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid JSON string.');
    }
}
