<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Exceptions\AuthConfigurationException;

/**
 * Model-backed {@see UserProvider}.
 *
 * Resolves users through the configured model class and verifies passwords with
 * PHP's password_* API. Retrieval (build a query from credentials) is kept
 * strictly separate from verification (constant-time hash check) so timing and
 * enumeration concerns can be handled in one place by the SessionGuard.
 */
class EloquentUserProvider implements UserProvider
{
    /** Whether the configured class has been checked to exist. */
    private bool $modelVerified = false;

    public function __construct(protected string $model)
    {
    }

    /**
     * The configured model class, checked to exist the first time it is asked for.
     *
     * Checked here rather than in the constructor because class_exists() loads
     * the class, and the model flattens nine Concerns traits. A guest never
     * reaches a user: SessionGuard::user() finds no identifier and answers null
     * without consulting this provider, so validating at construction loaded a
     * whole model hierarchy on every request from someone not logged in.
     *
     * @throws AuthConfigurationException When the configured class does not exist.
     */
    protected function model(): string
    {
        if (! $this->modelVerified) {
            if (! class_exists($this->model)) {
                throw new AuthConfigurationException(
                    "Configured auth model [{$this->model}] does not exist. "
                    . "Check the 'auth.model' config key."
                );
            }

            $this->modelVerified = true;
        }

        return $this->model;
    }

    /**
     * Retrieve a user by primary key.
     */
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        if ($identifier === null) {
            return null;
        }

        $user = ($this->model())::find($identifier);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Retrieve a user matching the non-password credentials. Returns null when
     * no usable credentials are given so an empty payload can never match the
     * first row in the table.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = ($this->model())::query();
        $usable = false;

        foreach ($credentials as $key => $value) {
            if ($this->isPasswordKey($key) || !is_scalar($value)) {
                continue;
            }
            $query->where($key, $value);
            $usable = true;
        }

        if (!$usable) {
            return null;
        }

        $user = $query->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Constant-time password check against the user's stored hash.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = (string) ($credentials['password'] ?? '');
        $hash  = $user->getAuthPassword();

        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Rehash the stored password if the hashing algorithm/cost has since
     * changed, so credentials transparently upgrade on the next successful
     * login. No-op unless the model exposes update().
     */
    /**
     * Find a user by identifier and remember-me token.
     *
     * Compared in constant time, because a token matched character by
     * character leaks how much of a guess was right.
     */
    public function retrieveByToken(mixed $identifier, string $token): ?Authenticatable
    {
        $user = $this->retrieveById($identifier);

        if ($user === null || $token === '') {
            return null;
        }

        $stored = $user->getRememberToken();

        return $stored !== null && $stored !== '' && hash_equals($stored, $token) ? $user : null;
    }

    public function updateRememberToken(Authenticatable $user, ?string $token): void
    {
        $user->setRememberToken($token);

        if (! method_exists($user, 'save')) {
            return;
        }

        // Saved without touching updated_at: reissuing a cookie is not a
        // change to the record, and a nightly sweep of stale accounts
        // should not see every remembered login as activity.
        $timestamps = method_exists($user, 'usesTimestamps') ? $user->usesTimestamps() : false;

        if ($timestamps && method_exists($user, 'withoutTimestamps')) {
            $user->withoutTimestamps(static fn () => $user->save());

            return;
        }

        $user->save();
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        $hash = $user->getAuthPassword();

        if (!$force && !password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            return;
        }

        if (!method_exists($user, 'update')) {
            return;
        }

        $column = method_exists($user, 'getAuthPasswordName')
            ? $user->getAuthPasswordName()
            : 'password';

        $user->update([$column => password_hash((string) ($credentials['password'] ?? ''), PASSWORD_DEFAULT)]);
    }

    /**
     * Credential keys that must never be used as query filters.
     */
    protected function isPasswordKey(string $key): bool
    {
        return $key === 'password' || str_ends_with($key, '_confirmation');
    }
}
