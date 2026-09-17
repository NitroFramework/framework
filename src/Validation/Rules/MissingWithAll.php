<?php

namespace Nitro\Validation\Rules;

/**
 * The field is absent when every one of the named fields is submitted.
 *
 * Usage: 'token' => 'missing_with_all:email,password'
 */
class MissingWithAll extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if (! $this->otherExists((string) $field)) {
                return true;
            }
        }

        return ! $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must not be present when ' . implode(' and ', $this->parameters) . ' are present.');
    }
}
