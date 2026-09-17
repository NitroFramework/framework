<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a hexadecimal colour, with or without alpha.
 *
 * Usage: 'tint' => 'hex_color'
 */
class HexColor extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return is_string($this->value) && preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $this->value) === 1;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a valid hexadecimal colour.');
    }
}
