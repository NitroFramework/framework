<?php

namespace Nitro\Validation\Rules;

/**
 * The value has the given number of decimal places, or a count in range.
 *
 * Usage: 'price' => 'decimal:2'  /  'decimal:1,4'
 */
class Decimal extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_numeric($this->value)) {
            return false;
        }

        $parts = explode('.', rtrim((string) $this->value, '.'));
        $places = isset($parts[1]) ? strlen($parts[1]) : 0;

        $min = (int) $this->getParameter(0);
        $max = $this->getParameter(1) === null ? $min : (int) $this->getParameter(1);

        return $places >= $min && $places <= $max;
    }

    public function message(): string
    {
        $max = $this->getParameter(1);

        return $this->replaceMessage($max === null
            ? 'The {attribute} must have ' . $this->getParameter(0) . ' decimal places.'
            : 'The {attribute} must have between ' . $this->getParameter(0) . ' and ' . $max . ' decimal places.');
    }
}
