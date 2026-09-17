<?php

namespace Nitro\Validation\Rules;

/**
 * The array contains each of the named keys.
 *
 * Usage: 'address' => 'required_array_keys:line1,city'
 */
class RequiredArrayKeys extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_array($this->value)) {
            return false;
        }

        foreach ($this->parameters as $key) {
            if (! array_key_exists((string) $key, $this->value)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must contain entries for: ' . implode(', ', $this->parameters) . '.');
    }
}
