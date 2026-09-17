<?php

namespace Nitro\Validation\Rules;

/**
 * The value does not match the given expression.
 *
 * Usage: 'name' => 'not_regex:/^admin/i'
 */
class NotRegex extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $pattern = $this->patternParameter();

        if ($pattern === null) {
            return true;
        }

        return is_scalar($this->value)
            && preg_match($pattern, (string) $this->value) !== 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} format is invalid.');
    }
}
