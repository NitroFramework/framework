<?php

namespace Nitro\Validation\Rules;

/**
 * The value's size is at most a number, or another field's size.
 *
 * Usage: 'min' => 'lte:max'
 */
class Lte extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->comparisonSize();
        $size = $this->sizeOf($this->value);

        return $other !== null && $size !== null && $size <= $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be less than or equal to ' . $this->getParameter(0) . '.');
    }
}
