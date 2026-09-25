<?php

namespace Nitro\Foundation\Contracts;

/**
 * Read and write application configuration by dot-notation key.
 */
interface ConfigRepository
{
    /** Determine whether the given key exists. */
    public function has(string $key): bool;

    /** Get the value at the given key, or $default when it is absent. */
    public function get(string $key, mixed $default = null): mixed;

    /** Get every configuration item. */
    public function all(): array;

    /** Set the value at the given key. */
    public function set(string $key, mixed $value): void;
}
