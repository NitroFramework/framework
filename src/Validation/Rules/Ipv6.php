<?php

namespace Nitro\Validation\Rules;

/**
 * The value is an IPv6 address.
 *
 * Usage: 'host' => 'ipv6'
 */
class Ipv6 extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && filter_var($this->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid IPv6 address.');
    }
}
