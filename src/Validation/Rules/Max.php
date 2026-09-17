<?php

namespace Nitro\Validation\Rules;

/**
 * Max Rule
 *
 * Upper bound on the value's size: a number's value, a string's length, an
 * array's count, or an upload's size in kilobytes.
 */
class Max extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $size = $this->sizeOf($this->value);

        // Nothing measurable — leave it to the type rules to reject.
        if ($size === null) {
            return true;
        }

        return $size <= (float) $this->getParameter(0);
    }

    public function message(): string
    {
        $max  = $this->getParameter(0);
        $unit = $this->sizeUnit($this->value);

        return $this->replaceMessage(
            $unit === ''
                ? "The {attribute} may not be greater than {$max}."
                : "The {attribute} may not exceed {$max} {$unit}."
        );
    }
}
