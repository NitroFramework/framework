<?php

namespace Nitro\Validation\Rules;

/**
 * The field is submitted when every one of the named fields is.
 *
 * Usage: 'postcode' => 'present_with_all:street,city'
 */
class PresentWithAll extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if (! $this->otherExists((string) $field)) {
                return true;
            }
        }

        return $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be present when ' . implode(' and ', $this->parameters) . ' are present.');
    }
}
