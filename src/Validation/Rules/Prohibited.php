<?php

namespace Nitro\Validation\Rules;

/**
 * The field is absent or empty.
 *
 * Usage: 'role' => 'prohibited'
 */
class Prohibited extends AbstractRule
{
    public function passes(): bool
    {
        return $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is prohibited.');
    }
}
