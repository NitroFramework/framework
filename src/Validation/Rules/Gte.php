<?php

namespace Nitro\Validation\Rules;

/**
 * The value's size is at least a number, or another field's size.
 *
 * Usage: 'max' => 'gte:min'
 */
class Gte extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->comparisonSize();
        $size = $this->sizeOf($this->value);

        return $other !== null && $size !== null && $size >= $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be greater than or equal to ' . $this->getParameter(0) . '.');
    }
}
