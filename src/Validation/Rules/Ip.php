<?php

namespace Nitro\Validation\Rules;

/**
 * The value is an IP address of either family.
 *
 * Usage: 'host' => 'ip'
 */
class Ip extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && filter_var($this->value, FILTER_VALIDATE_IP) !== false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid IP address.');
    }
}
