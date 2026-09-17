<?php

namespace Nitro\Validation\Rules;

/**
 * The field is submitted when any of the named fields is.
 *
 * Usage: 'postcode' => 'present_with:street'
 */
class PresentWith extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if ($this->otherExists((string) $field)) {
                return $this->valueExists();
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be present when ' . implode(' / ', $this->parameters) . ' is present.');
    }
}
