<?php

use Nitro\Auth\Contracts\Guard;
use Nitro\Container\Container;

if (!function_exists('auth')) {
    /**
     * Get the authentication guard
     *
     * @return Guard
     */
    function auth(): Guard
    {
        return Container::getInstance()->get('auth');
    }
}
