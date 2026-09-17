<?php

namespace Nitro\Validation\Rules;

/**
 * Abstract base class for all validation rules
 * 
 * All rule classes must extend this and implement passes() and message()
 */
abstract class AbstractRule
{
    protected string $attribute;
    protected mixed $value;
    protected array $data;
    protected array $parameters = [];

    /**
     * Determine if the validation rule passes
     * 
     * @return bool True if validation passes, false otherwise
     */
    abstract public function passes(): bool;

    /**
     * Get the validation error message
     * 
     * @return string Error message with placeholders replaced
     */
    abstract public function message(): string;

    /**
     * Set the attribute being validated
     */
    public function setAttribute(string $attribute): void
    {
        $this->attribute = $attribute;
    }

    /**
     * Set the value being validated
     */
    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    /**
     * Set the full data array
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Parse rule parameters from rule string
     * 
     * e.g., "max:255" -> parameters = [255]
     *       "in:active,inactive" -> parameters = ['active', 'inactive']
     */
    public function parseParameters(string $ruleString): void
    {
        // By default, extract parameter after colon
        if (str_contains($ruleString, ':')) {
            $param = substr($ruleString, strpos($ruleString, ':') + 1);
            $this->parameters = explode(',', $param);
        }
    }

    /**
     * Get a parameter by index
     */
    protected function getParameter(int $index = 0): mixed
    {
        return $this->parameters[$index] ?? null;
    }

    /**
     * Check if value is empty
     */
    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * The comparable size of a value, for max/min/between/size.
     *
     * Strings measure in characters, numbers by their value, arrays by count,
     * and uploads in kilobytes — so `max:2048` on a file means 2 MB, matching
     * how the rule reads in every other framework.
     */
    protected function sizeOf(mixed $value): ?float
    {
        if ($value instanceof \Nitro\Http\UploadedFile) {
            $bytes = $value->getSize();

            return $bytes === false ? null : $bytes / 1024;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return (float) mb_strlen($value);
        }

        if (is_array($value) || $value instanceof \Countable) {
            return (float) count($value);
        }

        return null;
    }

    /** Another field's value, by dot path. */
    protected function otherValue(string $field): mixed
    {
        return \Nitro\Support\Arr::get($this->data, $field);
    }

    /** Whether another field was submitted at all, empty or not. */
    protected function otherExists(string $field): bool
    {
        return \Nitro\Support\Arr::has($this->data, $field);
    }

    /** Whether another field was submitted with a non-empty value. */
    protected function otherFilled(string $field): bool
    {
        return ! $this->isEmpty($this->otherValue($field));
    }

    /**
     * Whether the field under validation was submitted at all.
     *
     * Distinct from a non-empty value: a field posted blank exists but is not
     * filled, and the present / missing / prohibited families turn on that.
     */
    protected function valueExists(): bool
    {
        return \Nitro\Support\Arr::has($this->data, $this->attribute);
    }

    /**
     * Whether the rule's condition holds, for the `_if` / `_unless` forms.
     *
     * The first parameter names a field and the rest are values it may hold;
     * the condition is true when the field holds any of them. With no values
     * listed, it is true when the field is filled.
     */
    protected function conditionMatches(): bool
    {
        $field = (string) $this->getParameter(0);

        if ($field === '') {
            return false;
        }

        $expected = array_slice($this->parameters, 1);

        if ($expected === []) {
            return $this->otherFilled($field);
        }

        $actual = $this->otherValue($field);

        foreach ($expected as $candidate) {
            if ((string) $candidate === (string) $actual) {
                return true;
            }
        }

        return false;
    }

    /**
     * A comparison target for gt / gte / lt / lte: another field's size when the
     * parameter names one, otherwise the parameter read as a number.
     */
    protected function comparisonSize(): ?float
    {
        $parameter = (string) $this->getParameter(0);

        if ($parameter === '') {
            return null;
        }

        if ($this->otherExists($parameter)) {
            return $this->sizeOf($this->otherValue($parameter));
        }

        return is_numeric($parameter) ? (float) $parameter : null;
    }

    /**
     * Whether any parameter satisfies $test against the value as a string.
     *
     * Shared by the starts_with / ends_with / contains family and their
     * negations, which differ only in the predicate and whether the answer is
     * inverted.
     *
     * @param callable(string, string): bool $test Receives (parameter, value).
     */
    protected function matchesAny(callable $test): bool
    {
        if (! is_scalar($this->value)) {
            return false;
        }

        $value = (string) $this->value;

        foreach ($this->parameters as $parameter) {
            if ($test((string) $parameter, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule's parameter as a usable preg pattern, or null when it is not one.
     *
     * A pattern may legitimately contain a comma, which parseParameters() would
     * otherwise split on, so the parameters are rejoined before use.
     */
    protected function patternParameter(): ?string
    {
        $pattern = implode(',', $this->parameters);

        if ($pattern === '') {
            return null;
        }

        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    /**
     * A date-like value as a Unix timestamp, or null when it will not parse.
     */
    protected function timestampOf(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * The timestamp a date rule compares against.
     *
     * The parameter names either another field, whose value is used, or a date
     * literal such as "2026-01-01" or "tomorrow".
     */
    protected function comparisonTimestamp(): ?int
    {
        $parameter = (string) $this->getParameter(0);

        if ($parameter === '') {
            return null;
        }

        $other = \Nitro\Support\Arr::get($this->data, $parameter);

        return $other !== null
            ? $this->timestampOf($other)
            : $this->timestampOf($parameter);
    }

    /** The unit name for a size-based message. */
    protected function sizeUnit(mixed $value): string
    {
        return match (true) {
            $value instanceof \Nitro\Http\UploadedFile => 'kilobytes',
            is_numeric($value)                          => '',
            is_string($value)                           => 'characters',
            is_array($value) || $value instanceof \Countable => 'items',
            default                                     => '',
        };
    }

    /**
     * Replace placeholders in message
     */
    protected function replaceMessage(string $message): string
    {
        return str_replace('{attribute}', $this->attribute, $message);
    }
}