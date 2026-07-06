<?php

namespace Nitro\Htmx\State;

/**
 * In-memory store — lives only for the current PHP request (or the
 * lifetime of the instance, whichever ends first). Built for tests and
 * for the ComponentHarness, where having persistent state survive
 * across test runs would be a correctness hazard.
 *
 * Not suitable for production use — every request gets a fresh empty
 * store, so widget state would never survive a click.
 */
class ArrayStateStore implements StateStore
{
    private array $data = [];

    public function get(string $key): ?array
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, array $value, ?int $ttl = null): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Test helper — wipe everything. */
    public function flush(): void
    {
        $this->data = [];
    }

    /** Test helper — full snapshot. */
    public function all(): array
    {
        return $this->data;
    }
}
