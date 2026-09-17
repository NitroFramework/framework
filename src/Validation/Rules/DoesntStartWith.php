<?php

namespace Nitro\Validation\Rules;

/**
 * The value begins with none of the given prefixes.
 *
 * Usage: 'slug' => 'doesnt_start_with:admin-'
 */
class DoesntStartWith extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return ! $this->matchesAny(static fn (string $needle, string $value) => str_starts_with($value, $needle));
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may not start with any of: ' . implode(', ', $this->parameters) . '.');
    }
}
