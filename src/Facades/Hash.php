<?php

namespace Nitro\Facades;

/**
 * Hash facade — hashes and verifies passwords.
 *
 *   Hash::make($password);
 *   Hash::check($password, $user->password);
 *
 * @method static string make(string $value, array $options = [])
 * @method static bool check(string $value, string $hashed)
 * @method static bool needsRehash(string $hashed, array $options = [])
 */
class Hash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hash';
    }
}
