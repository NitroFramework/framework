<?php

namespace Nitro\Htmx\State;

/**
 * Where component state lives. Each implementation hides the backend
 * details (PHP sessions, cache driver, in-memory array) behind a tiny
 * key/value/forget API that HasAutoState calls.
 *
 * Implementations are responsible for any per-user / per-scope key
 * isolation their backend needs. PHP sessions are scoped to the current
 * user automatically; a global Redis store is NOT — its impl prefixes
 * keys with the session ID so user A and user B can't trample each
 * other's instance state.
 */
interface StateStore
{
    /** Load the value at $key, or null if absent. */
    public function get(string $key): ?array;

    /** Save $value at $key. $ttl is a seconds hint — backends without TTL ignore it. */
    public function put(string $key, array $value, ?int $ttl = null): void;

    /** Delete the entry at $key. No-op if it doesn't exist. */
    public function forget(string $key): void;
}
