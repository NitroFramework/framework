<?php

namespace Nitro\Validation\Rules;

/**
 * The value's size equals the given number.
 *
 * Size means a number's value, a string's length, an array's count, or an
 * upload's size in kilobytes.
 *
 * Usage: 'code' => 'size:6'
 */
class Size extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $size = $this->sizeOf($this->value);

        return $size !== null && $size == (float) $this->getParameter(0);
    }

    public function message(): string
    {
        $unit = $this->sizeUnit($this->value);

        return $this->replaceMessage($unit === ''
            ? 'The {attribute} must be ' . $this->getParameter(0) . '.'
            : 'The {attribute} must be ' . $this->getParameter(0) . ' ' . $unit . '.');
    }
}
