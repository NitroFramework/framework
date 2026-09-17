<?php

namespace Nitro\Validation\Rules;

/**
 * The value ends with one of the given suffixes.
 *
 * Usage: 'file' => 'ends_with:.pdf,.docx'
 */
class EndsWith extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return $this->matchesAny(static fn (string $needle, string $value) => str_ends_with($value, $needle));
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must end with one of: ' . implode(', ', $this->parameters) . '.');
    }
}
