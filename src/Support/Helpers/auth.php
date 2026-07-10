<?php

use Nitro\Auth\Contracts\Guard;

if (!function_exists('auth')) {
    /**
     * Get the authentication guard
     *
     * @return Guard
     */
    function auth(): Guard
    {
        return app('auth');
    }
}
