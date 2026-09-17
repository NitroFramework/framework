<?php

namespace Nitro\Validation\Rules;

/**
 * The value's size is less than a number, or another field's size.
 *
 * Usage: 'min' => 'lt:max'
 */
class Lt extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->comparisonSize();
        $size = $this->sizeOf($this->value);

        return $other !== null && $size !== null && $size < $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be less than ' . $this->getParameter(0) . '.');
    }
}
