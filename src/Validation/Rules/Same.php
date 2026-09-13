<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value must match another field's.
 *
 * Usage: 'email_confirmation' => 'required|same:email'
 *
 * Loose comparison, because both sides arrive from the same form as strings and
 * an int-ish pair like 5 and '5' matching is what the user sees on screen.
 */
class Same extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->getParameter(0);

        return $this->value == Arr::get($this->data, (string) $other);
    }

    public function message(): string
    {
        $other = $this->getParameter(0);

        return $this->replaceMessage("The {attribute} and {$other} must match.");
    }
}
