<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value must NOT match another field's.
 *
 * Usage: 'password' => 'required|different:current_password'
 */
class Different extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $other = $this->getParameter(0);

        return $this->value != Arr::get($this->data, (string) $other);
    }

    public function message(): string
    {
        $other = $this->getParameter(0);

        return $this->replaceMessage("The {attribute} and {$other} must be different.");
    }
}
