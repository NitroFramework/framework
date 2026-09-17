<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required when none of the named fields is filled.
 *
 * Usage: 'email' => 'required_without_all:phone,username'
 */
class RequiredWithoutAll extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if ($this->otherFilled((string) $field)) {
                return true;
            }
        }

        return ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required when none of ' . implode(', ', $this->parameters) . ' are present.');
    }
}
