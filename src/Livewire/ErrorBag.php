<?php

namespace Nitro\Livewire;

use ArrayAccess;
use Countable;

/**
 * The validation error bag exposed to component views as $errors. Supports the
 * MessageBag-style API ($errors->has('email'), ->first('email'), ->get(),
 * ->all()) and array access ($errors['email']) for template convenience.
 */
class ErrorBag implements ArrayAccess, Countable
{
    /** @param array<string, string[]> $messages field => messages */
    public function __construct(private array $messages = []) {}

    public function has(string $field): bool
    {
        return ! empty($this->messages[$field]);
    }

    public function first(string $field): ?string
    {
        return $this->messages[$field][0] ?? null;
    }

    public function get(string $field): array
    {
        return $this->messages[$field] ?? [];
    }

    public function all(): array
    {
        return $this->messages;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->messages !== [];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->messages[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->messages[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
}
