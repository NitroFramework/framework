<?php

namespace Nitro\Validation\Rules;

/**
 * The value contains none of the given substrings.
 *
 * Usage: 'body' => 'doesnt_contain:spam'
 */
class DoesntContain extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return ! $this->matchesAny(static fn (string $needle, string $value) => str_contains($value, $needle));
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may not contain: ' . implode(', ', $this->parameters) . '.');
    }
}
