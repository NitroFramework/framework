<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Database\DB;

/**
 * Reads users straight from a table, with no model in the way.
 *
 * For an application whose users are not Eloquent — a legacy schema, or
 * one where the user record belongs to another service.
 */
class DatabaseUserProvider implements UserProvider
{
    public function __construct(protected string $table = 'users') {}

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        if ($identifier === null) {
            return null;
        }

        return $this->userFrom(DB::table($this->table)->where('id', $identifier)->first());
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = DB::table($this->table);
        $usable = false;

        foreach ($credentials as $key => $value) {
            if ($this->isPasswordKey($key) || ! is_scalar($value)) {
                continue;
            }

            $query->where($key, $value);
            $usable = true;
        }

        return $usable ? $this->userFrom($query->first()) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = (string) ($credentials['password'] ?? '');
        $hash = $user->getAuthPassword();

        return $plain !== '' && $hash !== '' && password_verify($plain, $hash);
    }

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

        DB::table($this->table)
            ->where('id', $user->getAuthIdentifier())
            ->update([$user->getRememberTokenName() => $token]);
    }

    /** Wrap a row, if there was one. */
    protected function userFrom(mixed $row): ?GenericUser
    {
        return $row === null ? null : new GenericUser((array) $row);
    }

    protected function isPasswordKey(string $key): bool
    {
        return $key === 'password' || str_ends_with($key, '_confirmation');
    }
}
