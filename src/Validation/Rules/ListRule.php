<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a list: an array keyed 0..n-1 with no gaps.
 *
 * Usage: 'items' => 'list'
 */
class ListRule extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_array($this->value) && array_is_list($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a list.');
    }
}
