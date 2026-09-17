<?php

namespace Nitro\Validation\Rules;

/**
 * The field is absent when any of the named fields is submitted.
 *
 * Usage: 'token' => 'missing_with:password'
 */
class MissingWith extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if ($this->otherExists((string) $field)) {
                return ! $this->valueExists();
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must not be present when ' . implode(' / ', $this->parameters) . ' is present.');
    }
}
