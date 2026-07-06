<?php

namespace Nitro\Auth\Contracts;

/**
 * Contract for retrieving and validating users out of some backing store.
 *
 * This is the seam that decouples *how* a user is fetched and credential-checked
 * from the SessionGuard's session/state logic. The default implementation
 * ({@see \Nitro\Auth\EloquentUserProvider}) reads from a model, but a token
 * table, an LDAP directory, or an API client could implement the same contract
 * without the manager knowing the difference.
 */
interface UserProvider
{
    /**
     * Retrieve a user by their unique identifier (the value stored in session).
     */
    public function retrieveById(mixed $identifier): ?Authenticatable;

    /**
     * Retrieve a user matching the given credentials, WITHOUT validating the
     * password. The password key is ignored here — it is only checked in
     * {@see validateCredentials()} so retrieval and verification stay separate.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /**
     * Validate a user's password against the given credentials.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}
