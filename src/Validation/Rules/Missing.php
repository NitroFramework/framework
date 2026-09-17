<?php

namespace Nitro\Validation\Rules;

/**
 * The field is not submitted at all.
 *
 * Usage: 'id' => 'missing'
 */
class Missing extends AbstractRule
{
    public function passes(): bool
    {
        return ! $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must not be present.');
    }
}
