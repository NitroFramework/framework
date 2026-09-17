<?php

namespace Nitro\Validation\Rules;

/**
 * The value is numeric with at least the given number of digits.
 *
 * Usage: 'code' => 'min_digits:4'
 */
class MinDigits extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $digits = (string) $this->value;

        return preg_match('/^[0-9]+$/', $digits) === 1
            && strlen($digits) >= (int) $this->getParameter(0);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must have at least ' . $this->getParameter(0) . ' digits.');
    }
}
