<?php

namespace Nitro\Validation\Rules;

/**
 * The value's host resolves in DNS.
 *
 * Performs a DNS lookup, so it is slower than `url` and depends on the network.
 *
 * Usage: 'website' => 'active_url'
 */
class ActiveUrl extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_string($this->value)) {
            return false;
        }

        $host = parse_url($this->value, PHP_URL_HOST) ?? $this->value;

        return is_string($host) && $host !== '' && checkdnsrr($host, 'A');
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is not a valid, resolvable URL.');
    }
}
