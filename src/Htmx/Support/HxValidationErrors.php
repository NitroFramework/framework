<?php

namespace Nitro\Htmx\Support;

class HxValidationErrors
{
    public function __construct(
        private array $errors = [],
    ) {}

    public function has(string $field): bool
    {
        return isset($this->errors[$field]) && count($this->errors[$field]) > 0;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function all(): array
    {
        $flat = [];
        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $flat[] = $error;
            }
        }
        return $flat;
    }

    public function any(): bool
    {
        return count($this->errors) > 0;
    }

    public function isEmpty(): bool
    {
        return !$this->any();
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function toArray(): array
    {
        return $this->errors;
    }

    public function keys(): array
    {
        return array_keys($this->errors);
    }
}