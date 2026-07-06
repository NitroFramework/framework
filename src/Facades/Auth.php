<?php

namespace Nitro\Facades;

/**
 * Auth facade — Laravel's `Auth::`.
 *
 *   Auth::check();  Auth::user();  Auth::id();
 *   Auth::attempt(['email' => ..., 'password' => ...]);
 *   Auth::login($user);  Auth::logout();
 *
 * @method static bool   check()
 * @method static bool   guest()
 * @method static mixed  user()
 * @method static mixed  id()
 * @method static bool   attempt(array $credentials)
 * @method static void   login(object $user)
 * @method static void   logout()
 */
class Auth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'auth';
    }
}
