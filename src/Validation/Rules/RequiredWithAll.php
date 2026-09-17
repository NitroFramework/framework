<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required when every one of the named fields is filled.
 *
 * Usage: 'postcode' => 'required_with_all:street,city'
 */
class RequiredWithAll extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if (! $this->otherFilled((string) $field)) {
                return true;
            }
        }

        return ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required when ' . implode(' and ', $this->parameters) . ' are present.');
    }
}
