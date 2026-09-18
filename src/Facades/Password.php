<?php

namespace Nitro\Facades;

/**
 * Password facade — sends reset links and resets passwords.
 *
 *   Password::sendResetLink(['email' => $email]);
 *
 * @method static string sendResetLink(array $credentials)
 * @method static string reset(array $credentials, \Closure $callback)
 * @method static void deleteToken(mixed $user)
 * @method static bool tokenExists(mixed $user, string $token)
 */
class Password extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'auth.password';
    }
}
