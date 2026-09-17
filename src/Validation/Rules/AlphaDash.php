<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains only letters, digits, hyphens and underscores.
 *
 * Usage: 'slug' => 'alpha_dash'
 */
class AlphaDash extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^[\pL\pM\pN_-]+$/u', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may only contain letters, numbers, dashes and underscores.');
    }
}
