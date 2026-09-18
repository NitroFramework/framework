<?php

namespace Nitro\Context;

use Closure;

/**
 * Data that travels with the current request or job.
 *
 *     Context::add('trace_id', $id);
 *     Context::get('trace_id');
 *
 * Anything added here is available for the rest of the request without being
 * threaded through every call, and is carried onto the jobs it dispatches so
 * a trace id survives the hop onto a queue.
 *
 * Hidden context works the same way but is left out of logs and payloads, for
 * values that should follow the work without being written down.
 */
class Repository
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<string, mixed> */
    protected array $hidden = [];

    // ─── Reading ──────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function missing(string $key): bool
    {
        return ! $this->has($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Read several keys at once.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /** Read a key and remove it. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    // ─── Writing ──────────────────────────────────────────

    /** @param string|array<string, mixed> $key */
    public function add(string|array $key, mixed $value = null): static
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $this->data[$name] = $item;
        }

        return $this;
    }

    /** Add only when the key is absent. */
    public function addIf(string $key, mixed $value): static
    {
        if ($this->missing($key)) {
            $this->add($key, $value);
        }

        return $this;
    }

    /** Append to a list held at this key. */
    public function push(string $key, mixed ...$values): static
    {
        $stack = $this->data[$key] ?? [];

        if (! is_array($stack)) {
            $stack = [$stack];
        }

        foreach ($values as $value) {
            $stack[] = $value;
        }

        $this->data[$key] = $stack;

        return $this;
    }

    /** Whether a list at this key contains the value. */
    public function stackContains(string $key, mixed $value): bool
    {
        $stack = $this->data[$key] ?? [];

        return is_array($stack) && in_array($value, $stack, true);
    }

    /** @param string|array<int, string> $keys */
    public function forget(string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            unset($this->data[$key]);
        }

        return $this;
    }

    // ─── Hidden ───────────────────────────────────────────

    public function getHidden(string $key, mixed $default = null): mixed
    {
        return $this->hidden[$key] ?? $default;
    }

    public function hasHidden(string $key): bool
    {
        return array_key_exists($key, $this->hidden);
    }

    /** @return array<string, mixed> */
    public function allHidden(): array
    {
        return $this->hidden;
    }

    /** @param string|array<string, mixed> $key */
    public function addHidden(string|array $key, mixed $value = null): static
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $this->hidden[$name] = $item;
        }

        return $this;
    }

    /** @param string|array<int, string> $keys */
    public function forgetHidden(string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            unset($this->hidden[$key]);
        }

        return $this;
    }

    // ─── Lifecycle ────────────────────────────────────────

    /** Run a callback with extra context, restoring what was there after. */
    public function scope(array $context, Closure $callback): mixed
    {
        $data = $this->data;
        $hidden = $this->hidden;

        $this->add($context);

        try {
            return $callback();
        } finally {
            $this->data = $data;
            $this->hidden = $hidden;
        }
    }

    /** Everything this context holds, for carrying onto a job. */
    public function dehydrate(): array
    {
        return ['data' => $this->data, 'hidden' => $this->hidden];
    }

    /** Restore context carried from wherever the work was dispatched. */
    public function hydrate(?array $context): static
    {
        $this->data = $context['data'] ?? [];
        $this->hidden = $context['hidden'] ?? [];

        return $this;
    }

    public function flush(): static
    {
        $this->data = [];
        $this->hidden = [];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->data === [] && $this->hidden === [];
    }
}
