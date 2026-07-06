<?php

namespace Nitro\Validation;

/**
 * ErrorBag
 * 
 * Structured error handling - stores and manages validation errors
 * Allows multiple errors per field and easy retrieval
 */
class ErrorBag implements \Countable
{
    /**
     * @var array<string, array<string>> Field => array of error messages
     */
    protected array $messages = [];

    /**
     * Add an error message for a field
     */
    public function add(string $field, string $message): void
    {
        if (!isset($this->messages[$field])) {
            $this->messages[$field] = [];
        }

        if (!in_array($message, $this->messages[$field])) {
            $this->messages[$field][] = $message;
        }
    }

    /**
     * Get all errors for a field
     * 
     * @return array<string> Error messages for the field
     */
    public function get(string $field): array
    {
        return $this->messages[$field] ?? [];
    }

    /**
     * Get the first error for a field
     * 
     * @return string|null First error message or null
     */
    public function first(string $field): ?string
    {
        return $this->messages[$field][0] ?? null;
    }

    /**
     * Get all errors as array
     * 
     * @return array<string, array<string>> All errors
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Check if there are no errors
     */
    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /**
     * Check if there are any errors
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Whether the bag holds any errors. Alias of {@see isNotEmpty()} —
     * the @hxErrors Blade directive (and Laravel-style templates) call ->any().
     */
    public function any(): bool
    {
        return $this->isNotEmpty();
    }

    /**
     * Get the count of all errors
     */
    public function count(): int
    {
        return array_sum(array_map('count', $this->messages));
    }

    /**
     * Check if a specific field has errors
     */
    public function has(string $field): bool
    {
        return isset($this->messages[$field]) && !empty($this->messages[$field]);
    }

    /**
     * Get errors as a flat array with dot notation
     * 
     * Useful for API responses
     * 
     * @return array Field.index => message
     */
    public function flattened(): array
    {
        $flat = [];
        
        foreach ($this->messages as $field => $errors) {
            foreach ($errors as $index => $message) {
                $key = $index > 0 ? "{$field}.{$index}" : $field;
                $flat[$key] = $message;
            }
        }

        return $flat;
    }

    /**
     * Clear all errors
     */
    public function clear(): void
    {
        $this->messages = [];
    }
}