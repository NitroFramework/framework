<?php

namespace Nitro\Foundation\Contracts;

/**
 * Configuration repository contract: dot-notation has/get/all/set over the
 * application configuration. The container binds the 'config' alias, the concrete
 * Config, and this interface to one instance; core classes inject this contract
 * and templates read via the config() helper.
 */
interface ConfigRepository
{
    /** Determine whether the given dot-notation key exists. */
    public function has(string $key): bool;

    /** Get the value at the given dot-notation key, or $default if absent. */
    public function get(string $key, mixed $default = null): mixed;

    /** All configuration items. */
    public function all(): array;

    /** Set the value at the given dot-notation key. */
    public function set(string $key, mixed $value): void;
}
