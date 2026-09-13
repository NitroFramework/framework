<?php

namespace Nitro\Validation\Rules;

/**
 * The value must be a date earlier than a given date, or than another field.
 *
 * Usage: 'starts_at' => 'required|date|before:ends_at'
 *
 * The mirror of {@see After}, whose date resolution it reuses.
 */
class Before extends After
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $value = $this->timestamp($this->value);
        $other = $this->comparisonTimestamp();

        if ($value === null || $other === null) {
            return false;
        }

        return $value < $other;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a date before ' . $this->getParameter(0) . '.');
    }
}
