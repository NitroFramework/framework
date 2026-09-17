<?php

namespace Nitro\Validation\Rules;

/**
 * The value is numeric with a digit count in the given range.
 *
 * Usage: 'code' => 'digits_between:4,6'
 */
class DigitsBetween extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $digits = (string) $this->value;

        if (preg_match('/^[0-9]+$/', $digits) !== 1) {
            return false;
        }

        $length = strlen($digits);

        return $length >= (int) $this->getParameter(0)
            && $length <= (int) $this->getParameter(1);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be between ' . $this->getParameter(0) . ' and ' . $this->getParameter(1) . ' digits.');
    }
}
