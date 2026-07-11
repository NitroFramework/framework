<?php

namespace Nitro\Session;

use Closure;
use Nitro\Session\Contracts\SessionInterface;
use SessionHandlerInterface;

/**
 * Object-oriented session store backed by a pluggable handler.
 *
 * Holds attributes in memory for the duration of a request, loaded from the
 * handler on {@see start()} and written back on {@see save()}. Supports dot
 * notation, the new/old flash lifecycle, CSRF token management, and id
 * regeneration. No reliance on PHP's $_SESSION or session_start(), so a fresh
 * Store per request makes sessions worker-safe by construction.
 */
class Store implements SessionInterface
{
    protected string $id;
    protected array $attributes = [];
    protected bool $started = false;

    public function __construct(
        protected string $name,
        protected SessionHandlerInterface $handler,
        ?string $id = null,
    ) {
        $this->setId($id);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────

    public function start(): bool
    {
        $this->attributes = array_merge($this->attributes, $this->readFromHandler());

        if (!$this->has('_csrf')) {
            $this->regenerateToken();
        }

        return $this->started = true;
    }

    public function save(): void
    {
        $this->ageFlashData();
        $this->handler->write($this->id, serialize($this->attributes));
        $this->started = false;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** Read + unserialize the persisted payload for the current id. */
    protected function readFromHandler(): array
    {
        $data = $this->handler->read($this->id);
        if ($data === '' || $data === false) {
            return [];
        }
        $decoded = @unserialize($data);
        return is_array($decoded) ? $decoded : [];
    }

    // ─── Identity ─────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(?string $id): void
    {
        $this->id = $this->isValidId($id) ? $id : $this->generateSessionId();
    }

    protected function isValidId(?string $id): bool
    {
        return is_string($id) && ctype_alnum($id) && strlen($id) === 40;
    }

    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(20)); // 40 hex chars
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    // ─── Reading ──────────────────────────────────────────────────────────

    public function all(): array
    {
        return $this->attributes;
    }

    public function exists(string $key): bool
    {
        return $this->dotExists($this->attributes, $key);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->dotGet($this->attributes, $key, $default);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    // ─── Writing ──────────────────────────────────────────────────────────

    public function put(string|array $key, mixed $value = null): void
    {
        $pairs = is_array($key) ? $key : [$key => $value];
        foreach ($pairs as $k => $v) {
            $this->dotSet($this->attributes, $k, $v);
        }
    }

    public function push(string $key, mixed $value): void
    {
        $array = $this->get($key, []);
        if (!is_array($array)) {
            $array = [];
        }
        $array[] = $value;
        $this->put($key, $array);
    }

    public function remember(string $key, Closure $callback): mixed
    {
        if (($value = $this->get($key)) !== null) {
            return $value;
        }
        $this->put($key, $value = $callback());
        return $value;
    }

    public function increment(string $key, int $amount = 1): int
    {
        $value = (int) $this->get($key, 0) + $amount;
        $this->put($key, $value);
        return $value;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, $amount * -1);
    }

    // ─── Flash ────────────────────────────────────────────────────────────

    public function flash(string $key, mixed $value = true): void
    {
        $this->put($key, $value);
        $this->push('_flash.new', $key);
        $this->removeFromOldFlashData([$key]);
    }

    public function now(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $this->push('_flash.old', $key);
    }

    public function reflash(): void
    {
        $this->mergeNewFlashes($this->get('_flash.old', []));
        $this->put('_flash.old', []);
    }

    public function keep(array|string|null $keys = null): void
    {
        $keys = $keys === null ? $this->get('_flash.old', []) : (array) $keys;
        $this->mergeNewFlashes($keys);
        $this->removeFromOldFlashData($keys);
    }

    public function ageFlashData(): void
    {
        $this->forget($this->get('_flash.old', []));
        $this->put('_flash.old', $this->get('_flash.new', []));
        $this->put('_flash.new', []);
    }

    protected function mergeNewFlashes(array $keys): void
    {
        $values = array_unique(array_merge($this->get('_flash.new', []), $keys));
        $this->put('_flash.new', $values);
    }

    protected function removeFromOldFlashData(array $keys): void
    {
        $this->put('_flash.old', array_diff($this->get('_flash.old', []), $keys));
    }

    // ─── Removal ──────────────────────────────────────────────────────────

    public function forget(array|string $keys): void
    {
        foreach ((array) $keys as $key) {
            $this->dotForget($this->attributes, $key);
        }
    }

    public function flush(): void
    {
        $this->attributes = [];
    }

    public function invalidate(): bool
    {
        $this->flush();
        return $this->migrate(true);
    }

    public function regenerate(bool $destroy = false): bool
    {
        $migrated = $this->migrate($destroy);
        $this->regenerateToken();
        return $migrated;
    }

    /** Assign a fresh id, optionally destroying the persisted old one. */
    public function migrate(bool $destroy = false): bool
    {
        if ($destroy) {
            $this->handler->destroy($this->id);
        }
        $this->setId($this->generateSessionId());
        return true;
    }

    // ─── CSRF token ───────────────────────────────────────────────────────

    public function token(): ?string
    {
        return $this->get('_csrf');
    }

    public function regenerateToken(): void
    {
        // Canonical CSRF key is '_csrf' — the same key csrf_token(), the
        // VerifyCsrfToken middleware, Livewire and HTMX all read/write. Keeping
        // token()/regenerateToken() on this key means session()->token() returns
        // the token the framework actually verifies (and regenerate() rotates it).
        $this->put('_csrf', bin2hex(random_bytes(20)));
    }

    public function getHandler(): SessionHandlerInterface
    {
        return $this->handler;
    }

    // ─── Dot-notation helpers ─────────────────────────────────────────────

    private function dotGet(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        if (!str_contains($key, '.')) {
            return $default;
        }
        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        return $array;
    }

    private function dotExists(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }
        if (!str_contains($key, '.')) {
            return false;
        }
        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return false;
            }
        }
        return true;
    }

    private function dotSet(array &$array, string $key, mixed $value): void
    {
        if (!str_contains($key, '.')) {
            $array[$key] = $value;
            return;
        }
        $segments = explode('.', $key);
        $ref = &$array;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    private function dotForget(array &$array, string $key): void
    {
        if (array_key_exists($key, $array)) {
            unset($array[$key]);
            return;
        }
        if (!str_contains($key, '.')) {
            return;
        }
        $segments = explode('.', $key);
        $ref = &$array;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                unset($ref[$segment]);
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                return;
            }
            $ref = &$ref[$segment];
        }
    }
}
