<?php

namespace Nitro\Validation\Rules;

/**
 * The value must be an array.
 *
 * Usage: 'seats' => 'required|array'
 *
 * Named ArrayRule because `array` is a PHP reserved word and cannot be a class
 * name; it registers under 'array', which is what a rule string says. Same
 * reason as {@see StringRule}.
 */
class ArrayRule extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_array($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be an array.');
    }
}
