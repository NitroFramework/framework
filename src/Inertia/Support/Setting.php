<?php

namespace Nitro\Inertia\Support;

/**
 * A configuration read that cannot bring down a response.
 *
 * These settings are all opt-in switches with a working default, and they are
 * read while a request is being served. A context without a config repository
 * bound — a console run, a test exercising the protocol on its own — should
 * get the default rather than an error raised on the way out of a request that
 * had otherwise succeeded.
 */
final class Setting
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
