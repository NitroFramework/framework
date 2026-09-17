<?php

namespace Nitro\Validation\Rules;

/**
 * The value divides exactly by the given number.
 *
 * Usage: 'quantity' => 'multiple_of:5'
 */
class MultipleOf extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_numeric($this->value)) {
            return false;
        }

        $divisor = (float) $this->getParameter(0);

        if ($divisor == 0.0) {
            return false;
        }

        $remainder = fmod((float) $this->value, $divisor);

        return abs($remainder) < 1e-9 || abs(abs($remainder) - abs($divisor)) < 1e-9;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a multiple of ' . $this->getParameter(0) . '.');
    }
}
