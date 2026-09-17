<?php

namespace Nitro\Validation\Rules;

/**
 * The value parses exactly against one of the given formats.
 *
 * Exactly: the reformatted date must equal the input, so '2026-1-1' fails
 * 'Y-m-d' rather than being silently accepted.
 *
 * Usage: 'when' => 'date_format:Y-m-d,Y-m-d H:i:s'
 */
class DateFormat extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_string($this->value) || $this->value === '') {
            return false;
        }

        foreach ($this->parameters as $format) {
            $parsed = \DateTime::createFromFormat((string) $format, $this->value);

            if ($parsed !== false && $parsed->format((string) $format) === $this->value) {
                return true;
            }
        }

        return false;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} does not match the format ' . implode(' or ', $this->parameters) . '.');
    }
}
