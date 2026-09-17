<?php

namespace Nitro\Validation\Rules;

/**
 * The value begins with one of the given prefixes.
 *
 * Usage: 'path' => 'starts_with:/admin,/api'
 */
class StartsWith extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return $this->matchesAny(static fn (string $needle, string $value) => str_starts_with($value, $needle));
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must start with one of: ' . implode(', ', $this->parameters) . '.');
    }
}
