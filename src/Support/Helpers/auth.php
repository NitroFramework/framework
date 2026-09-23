<?php

use Nitro\Auth\AuthManager;
use Nitro\Auth\Contracts\Guard;

if (!function_exists('auth')) {
    /**
     * The authentication manager, or one of its guards by name.
     *
     * Calls the manager does not answer go to the default guard, so
     * auth()->user() reads the same whether an application has one
     * guard or several.
     */
    function auth(?string $guard = null): AuthManager|Guard
    {
        $auth = app('auth');

        return $guard === null ? $auth : $auth->guard($guard);
    }
}
