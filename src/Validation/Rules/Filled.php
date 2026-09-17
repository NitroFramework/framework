<?php

namespace Nitro\Validation\Rules;

/**
 * The field, when submitted, is not empty.
 *
 * A field left out entirely passes; one submitted blank does not.
 *
 * Usage: 'name' => 'filled'
 */
class Filled extends AbstractRule
{
    public function passes(): bool
    {
        return ! $this->valueExists() || ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must have a value.');
    }
}
