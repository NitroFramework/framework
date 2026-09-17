<?php

namespace Nitro\Validation\Rules;

/**
 * The value is numeric with at most the given number of digits.
 *
 * Usage: 'code' => 'max_digits:6'
 */
class MaxDigits extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $digits = (string) $this->value;

        return preg_match('/^[0-9]+$/', $digits) === 1
            && strlen($digits) <= (int) $this->getParameter(0);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may not have more than ' . $this->getParameter(0) . ' digits.');
    }
}
