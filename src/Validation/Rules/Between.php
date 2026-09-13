<?php

namespace Nitro\Validation\Rules;

/**
 * The value must fall within an inclusive range.
 *
 * Usage: 'quantity' => 'required|integer|between:1,500'
 *
 * What is measured depends on the type, matching min/max: a number is compared
 * by value, a string by length, an array by count. So between:1,500 on a
 * quantity means "one to five hundred seats" and on a title means "one to five
 * hundred characters", which is what each field's author meant.
 */
class Between extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $min = (float) $this->getParameter(0);
        $max = (float) $this->getParameter(1);
        $size = $this->size();

        return $size >= $min && $size <= $max;
    }

    private function size(): float
    {
        if (is_numeric($this->value)) {
            return (float) $this->value;
        }

        if (is_array($this->value)) {
            return (float) count($this->value);
        }

        return (float) mb_strlen((string) $this->value);
    }

    public function message(): string
    {
        $min = $this->getParameter(0);
        $max = $this->getParameter(1);

        return $this->replaceMessage("The {attribute} must be between {$min} and {$max}.");
    }
}
