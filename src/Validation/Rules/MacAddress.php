<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a MAC address.
 *
 * Usage: 'device' => 'mac_address'
 */
class MacAddress extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && filter_var($this->value, FILTER_VALIDATE_MAC) !== false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid MAC address.');
    }
}
