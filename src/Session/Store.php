<?php

namespace Nitro\Session;

use Closure;
use Nitro\Session\Contracts\Session;
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
class Store implements Session
{
    /** Length of a generated session id, in characters. */
    protected const SESSION_ID_LENGTH = 40;

    protected string $id;
    protected array $attributes = [];
    protected bool $started = false;

    /**
     * @param string $name          Name of the cookie carrying the session id.
     * @param string $serialization 'php' or 'json'.
     */
    public function __construct(
        protected string $name,
        protected SessionHandlerInterface $handler,
        ?string $id = null,
        protected string $serialization = 'php',
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

        $this->handler->write($this->id, $this->prepareForStorage(
            $this->serialization === 'json'
                ? json_encode($this->attributes)
                : serialize($this->attributes)
        ));

        $this->started = false;
    }

    /**
     * Prepare the serialized payload for the handler.
     *
     * A seam for subclasses; {@see EncryptedStore} encrypts here.
     */
    protected function prepareForStorage(string $data): string
    {
        return $data;
    }

    /**
     * Prepare the handler's raw payload for unserialization.
     */
    protected function prepareForUnserialize(string $data): string
    {
        return $data;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Remove persisted sessions idle longer than $minutes.
     *
     * Runs on a lottery after the response has been sent, so an application
     * never has to be told to clean up after itself. $limit caps how much one
     * sweep does, keeping the cost the same on a directory of any size.
     */
    public function collectGarbage(int $minutes, int $limit = 0): void
    {
        $this->handler->gc($minutes * 60, $limit);
    }

    /** Read + unserialize the persisted payload for the current id. */
    protected function readFromHandler(): array
    {
        $data = $this->handler->read($this->id);
        if ($data === '' || $data === false) {
            return [];
        }

        $decoded = $this->serialization === 'json'
            ? json_decode($this->prepareForUnserialize($data), true)
            : @unserialize($this->prepareForUnserialize($data));

        return is_array($decoded) ? $decoded : [];
    }

    // ─── Identity ─────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    /** Alias of {@see getId()}. */
    public function id(): string
    {
        return $this->getId();
    }

    public function setId(?string $id): void
    {
        $this->id = $this->isValidId($id) ? $id : $this->generateSessionId();
    }

    /** Determine whether a string is a well-formed session id. */
    public function isValidId(?string $id): bool
    {
        return is_string($id) && ctype_alnum($id) && strlen($id) === self::SESSION_ID_LENGTH;
    }

    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(self::SESSION_ID_LENGTH / 2));
    }

    /** Tell an existence-aware handler whether this session is already persisted. */
    public function setExists(bool $value): void
    {
        if ($this->handler instanceof ExistenceAwareInterface) {
            $this->handler->setExists($value);
        }
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

    /**
     * Determine whether any of the given keys is present and not null.
     *
     * @param array<int, string>|string $keys
     */
    public function hasAny(array|string $keys): bool
    {
        foreach ((array) $keys as $key) {
            if ($this->get($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Determine whether a key is absent from the session. */
    public function missing(string $key): bool
    {
        return ! $this->exists($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->dotGet($this->attributes, $key, $default);
    }

    /**
     * Get only the given keys.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->attributes, array_flip($keys));
    }

    /**
     * Get everything except the given keys.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->attributes, array_flip($keys));
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /** Get a key's value and remove it from the session. */
    public function remove(string $key): mixed
    {
        return $this->pull($key);
    }

    // ─── Writing ──────────────────────────────────────────────────────────

    public function put(string|array $key, mixed $value = null): void
    {
        $pairs = is_array($key) ? $key : [$key => $value];
        foreach ($pairs as $attributeKey => $attributeValue) {
            $this->dotSet($this->attributes, $attributeKey, $attributeValue);
        }
    }

    /**
     * Put the given key/value pairs into the session.
     *
     * @param array<string, mixed> $attributes
     */
    public function replace(array $attributes): void
    {
        $this->put($attributes);
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

    /**
     * Flash an input array to the session for the next request.
     *
     * @param array<string, mixed> $value
     */
    public function flashInput(array $value): void
    {
        $this->flash('_old_input', $value);
    }

    /**
     * Get an item from the flashed input, or all of it when no key is given.
     */
    public function getOldInput(?string $key = null, mixed $default = null): mixed
    {
        $old = $this->get('_old_input', []);

        if ($key === null) {
            return $old;
        }

        return is_array($old) ? $this->dotGet($old, $key, $default) : $default;
    }

    /**
     * Determine whether flashed input exists, optionally for one key.
     */
    public function hasOldInput(?string $key = null): bool
    {
        $old = $this->getOldInput($key);

        return $key === null ? count((array) $old) > 0 : $old !== null;
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

    // ─── Previous request ─────────────────────────────────────────────────

    /** Get the URL the user was last at, if one was recorded. */
    public function previousUrl(): ?string
    {
        return $this->get('_previous.url');
    }

    public function setPreviousUrl(string $url): void
    {
        $this->put('_previous.url', $url);
    }

    /** Get the name of the route the user was last at, if one was recorded. */
    public function previousRoute(): ?string
    {
        return $this->get('_previous.route');
    }

    public function setPreviousRoute(string $route): void
    {
        $this->put('_previous.route', $route);
    }

    public function hasPreviousUri(): bool
    {
        return $this->previousUrl() !== null;
    }

    /** Record that the user confirmed their password just now. */
    public function passwordConfirmed(): void
    {
        $this->put('auth.password_confirmed_at', time());
    }

    // ─── Handler ──────────────────────────────────────────────────────────

    public function getHandler(): SessionHandlerInterface
    {
        return $this->handler;
    }

    public function setHandler(SessionHandlerInterface $handler): SessionHandlerInterface
    {
        return $this->handler = $handler;
    }

    /** Determine whether the handler needs the request to do its job. */
    public function handlerNeedsRequest(): bool
    {
        return $this->handler instanceof CookieSessionHandler;
    }

    /** Give the handler the current request, when it needs one. */
    public function setRequestOnHandler(mixed $request): void
    {
        if ($this->handlerNeedsRequest()) {
            $this->handler->setRequest($request);
        }
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
