<?php

namespace Nitro\Session\Contracts;

/**
 * The session store contract.
 *
 * An object abstraction over session state (no raw $_SESSION access). Backed by
 * a pluggable {@see \SessionHandlerInterface} driver and driven through an
 * explicit start()/save() lifecycle, which is what makes it safe in long-running
 * workers (FrankenPHP/RoadRunner) where PHP's native session machinery is not.
 */
interface SessionInterface
{
    /** Start the session: load persisted data and ensure a CSRF token exists. */
    public function start(): bool;

    /** Persist the session (ages flash data, writes through the handler). */
    public function save(): void;

    /** The session id. */
    public function getId(): string;

    /** Set the session id (regenerates a fresh one if invalid). */
    public function setId(?string $id): void;

    /** The session cookie name. */
    public function getName(): string;

    /** Every attribute as an array. */
    public function all(): array;

    /** Whether a key exists (even if null). Accepts dot notation. */
    public function exists(string $key): bool;

    /** Whether a key exists and is non-null. Accepts dot notation. */
    public function has(string $key): bool;

    /** Get a value (dot notation), or $default when absent. */
    public function get(string $key, mixed $default = null): mixed;

    /** Get a value and immediately forget it. */
    public function pull(string $key, mixed $default = null): mixed;

    /** Put a key/value (or an array of pairs). */
    public function put(string|array $key, mixed $value = null): void;

    /** Push a value onto an array-valued key. */
    public function push(string $key, mixed $value): void;

    /** Get a value, or store and return the result of $callback when absent. */
    public function remember(string $key, \Closure $callback): mixed;

    /** Flash a value for the next request only. */
    public function flash(string $key, mixed $value = true): void;

    /** Flash a value for the current request only. */
    public function now(string $key, mixed $value): void;

    /** Re-flash all current flash data for another request. */
    public function reflash(): void;

    /** Keep the given flash keys (or all) for another request. */
    public function keep(array|string|null $keys = null): void;

    /** Age flash data: drop last request's, promote this request's. */
    public function ageFlashData(): void;

    /** Forget one or more keys (dot notation). */
    public function forget(array|string $keys): void;

    /** Remove all attributes. */
    public function flush(): void;

    /** Flush + regenerate id — fully drop the session. */
    public function invalidate(): bool;

    /** Generate a new session id, optionally destroying the old store entry. */
    public function regenerate(bool $destroy = false): bool;

    /** The CSRF token. */
    public function token(): ?string;

    /** Generate a new CSRF token. */
    public function regenerateToken(): void;
}
