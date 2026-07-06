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
     * Replace placeholders in message
     */
    protected function replaceMessage(string $message): string
    {
        return str_replace('{attribute}', $this->attribute, $message);
    }
}