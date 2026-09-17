<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a UUID.
 *
 * Usage: 'id' => 'uuid'
 */
class Uuid extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid UUID.');
    }
}
