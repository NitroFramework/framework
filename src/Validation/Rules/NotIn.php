<?php

namespace Nitro\Validation\Rules;

/**
 * The value is none of the listed values.
 *
 * Usage: 'role' => 'not_in:root,superuser'
 */
class NotIn extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if ($this->isEmpty($this->value)) {
            return true;
        }

        return ! in_array((string) $this->value, array_map('strval', $this->parameters), true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The selected {attribute} is invalid.');
    }
}
