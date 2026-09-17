<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required when any of the named fields is not filled.
 *
 * Usage: 'email' => 'required_without:phone'
 */
class RequiredWithout extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if (! $this->otherFilled((string) $field)) {
                return ! $this->isEmpty($this->value);
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required when ' . implode(' / ', $this->parameters) . ' is not present.');
    }
}
