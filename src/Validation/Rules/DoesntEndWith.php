<?php

namespace Nitro\Validation\Rules;

/**
 * The value ends with none of the given suffixes.
 *
 * Usage: 'file' => 'doesnt_end_with:.exe'
 */
class DoesntEndWith extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return ! $this->matchesAny(static fn (string $needle, string $value) => str_ends_with($value, $needle));
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} may not end with any of: ' . implode(', ', $this->parameters) . '.');
    }
}
