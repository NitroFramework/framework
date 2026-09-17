<?php

namespace Nitro\Validation\Rules;

/**
 * The field is submitted, even if empty.
 *
 * Usage: 'nickname' => 'present'
 */
class Present extends AbstractRule
{
    public function passes(): bool
    {
        return $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be present.');
    }
}
