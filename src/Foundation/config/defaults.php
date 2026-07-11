<?php

/**
 * Framework configuration defaults.
 *
 * Loaded by Config as the BASE layer; the application's config/*.php is then
 * recursively merged on top, so the app always wins and any key it omits still
 * resolves to a sane framework value. This is the single source of truth for the
 * defaults framework internals rely on — internals read config('key') WITHOUT an
 * inline fallback, because the key is guaranteed to exist.
 *
 * Only keys that have a meaningful framework-level default live here. App-specific
 * values (database credentials, app key, filesystem paths) intentionally have no
 * default — the application must provide them. The htmx.* keys are omitted on
 * purpose: config/htmx.php ships with the framework and always supplies them.
 *
 * Values mirror the shipped config/*.php; where inline fallbacks had drifted
 * (app.debug, view.cache.expiry, view.cache.use_*), the config/*.php value wins.
 */

return [

    'app' => [
        'env'                   => 'production',
        'debug'                 => false,
        'url'                   => 'http://localhost',
        'controllers_namespace' => 'App\\Controllers\\',
        'providers'             => [],
        // IPs of proxies/load balancers whose X-Forwarded-* headers may be
        // trusted (an array of exact REMOTE_ADDR values, or '*' to trust all —
        // only safe when the app is reachable ONLY through a known proxy).
        // Empty = trust nothing, so Request::ip()/secure() ignore forwarded
        // headers and a client can't spoof its IP or scheme.
        'trusted_proxies'       => [],
    ],

    'auth' => [
        'password_timeout' => 10800,
        'redirects' => [
            'login'            => '/login',
            'dashboard'        => '/dashboard',
            'verification'     => '/verify-email',
            'password_confirm' => '/confirm-password',
        ],
    ],

    // No config/mail.php ships, so this is the sole source of the mail default.
    'mail' => [
        'driver' => 'log',
    ],

    'queue' => [
        'default' => 'database',
        'failed'  => [
            'table' => 'failed_jobs',
        ],
    ],

    'session' => [
        'driver'   => 'native',
        'lifetime' => 120,
        'cookie'   => 'nitro_session',
        'files'    => null,
    ],

    'view' => [
        'extension'    => 'blade.php',
        'debug_render' => false,
        'cache' => [
            'enabled'     => true,
            'expiry'      => 0,
            'use_opcache' => false,
            'use_locks'   => false,
        ],
    ],

];
