<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value appears in another field's array.
 *
 * Usage: 'choice' => 'in_array:options.*'
 */
class InArray extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $field = rtrim((string) $this->getParameter(0), '.*');
        $other = Arr::get($this->data, $field);

        return is_array($other) && in_array($this->value, $other);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} does not exist in ' . rtrim((string) $this->getParameter(0), '.*') . '.');
    }
}
