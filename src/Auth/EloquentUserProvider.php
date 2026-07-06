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
    public function __construct(protected string $model)
    {
        if (!class_exists($this->model)) {
            throw new AuthConfigurationException(
                "Configured auth model [{$this->model}] does not exist. "
                . "Check the 'auth.model' config key."
            );
        }
    }

    /**
     * Retrieve a user by primary key.
     */
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        if ($identifier === null) {
            return null;
        }

        $user = ($this->model)::find($identifier);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Retrieve a user matching the non-password credentials. Returns null when
     * no usable credentials are given so an empty payload can never match the
     * first row in the table.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = ($this->model)::query();
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
