<?php

namespace Nitro\Support;

/**
 * Password hashing, Laravel's `Hash` facade surface.
 *
 *   Hash::make($plain)            // hash for storage
 *   Hash::check($plain, $hashed)  // verify
 *   Hash::needsRehash($hashed)    // algorithm/cost moved on?
 *
 * Thin wrapper over PHP's password_* API (bcrypt by default), centralised so the
 * algorithm/cost lives in one place and credentials can transparently upgrade.
 */
class Hash
{
    public static function make(string $value, array $options = []): string
    {
        return password_hash($value, PASSWORD_DEFAULT, $options);
    }

    public static function check(string $value, ?string $hashedValue): bool
    {
        if ($hashedValue === null || $hashedValue === '') {
            return false;
        }

        return password_verify($value, $hashedValue);
    }

    public static function needsRehash(string $hashedValue, array $options = []): bool
    {
        return password_needs_rehash($hashedValue, PASSWORD_DEFAULT, $options);
    }
}
