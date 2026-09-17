<?php

namespace Nitro\Validation\Rules;

/**
 * Min Rule
 *
 * Lower bound on the value's size: a number's value, a string's length, an
 * array's count, or an upload's size in kilobytes.
 */
class Min extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $size = $this->sizeOf($this->value);

        if ($size === null) {
            return true;
        }

        return $size >= (float) $this->getParameter(0);
    }

    public function message(): string
    {
        $min  = $this->getParameter(0);
        $unit = $this->sizeUnit($this->value);

        return $this->replaceMessage(
            $unit === ''
                ? "The {attribute} must be at least {$min}."
                : "The {attribute} must be at least {$min} {$unit}."
        );
    }
}
