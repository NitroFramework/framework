<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value must be a date later than a given date, or than another field.
 *
 * Usage: 'expires_at' => 'required|date|after:today'
 *        'ends_at'    => 'required|date|after:starts_at'
 *
 * The parameter is read as another field first and as a date string second, so
 * after:starts_at compares two submitted dates while after:today compares
 * against the clock. A parameter that is neither fails rather than passing
 * silently — a rule that cannot be evaluated has not been satisfied.
 */
class After extends AbstractRule
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

        return $value > $other;
    }

    protected function comparisonTimestamp(): ?int
    {
        $parameter = (string) $this->getParameter(0);
        $other = Arr::get($this->data, $parameter);

        return $this->timestamp($other ?? $parameter);
    }

    protected function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be a date after ' . $this->getParameter(0) . '.');
    }
}
