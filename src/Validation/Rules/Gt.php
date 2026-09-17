<?php

namespace Nitro\Validation\Rules;

/**
 * The value's size is greater than a number, or another field's size.
 *
 * Usage: 'max' => 'gt:min'
 */
class Gt extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->comparisonSize();
        $size = $this->sizeOf($this->value);

        return $other !== null && $size !== null && $size > $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be greater than ' . $this->getParameter(0) . '.');
    }
}
