<?php

namespace Nitro\Auth\Contracts;

/**
 * Contract for any entity that can be authenticated by the auth layer.
 *
 * Decouples the SessionGuard from a concrete model: the manager only ever needs
 * a stable identifier (for the session) and the stored password hash (for
 * credential verification). Implemented by app models via the
 * {@see \Nitro\Auth\Concerns\Authenticatable} trait, but a plain value object
 * works just as well.
 */
interface Authenticatable
{
    /**
     * Name of the unique identifier column (e.g. "id"). Lets the provider build
     * a lookup query without assuming the key name.
     */
    public function getAuthIdentifierName(): string;

    /**
     * The unique identifier value stored in the session. May be an int or a
     * string (UUID/ULID) — never cast it to a specific type upstream.
     */
    public function getAuthIdentifier(): mixed;

    /**
     * The hashed password used for credential verification.
     */
    public function getAuthPassword(): string;
}
