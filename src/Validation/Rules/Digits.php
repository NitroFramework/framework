<?php

namespace Nitro\Validation\Rules;

/**
 * The value is numeric and has exactly the given number of digits.
 *
 * Usage: 'pin' => 'digits:4'
 */
class Digits extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $digits = (string) $this->value;

        return preg_match('/^[0-9]+$/', $digits) === 1
            && strlen($digits) === (int) $this->getParameter(0);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be ' . $this->getParameter(0) . ' digits.');
    }
}
