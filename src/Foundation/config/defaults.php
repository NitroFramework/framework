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
 * default — the application must provide them.
 *
 * Values mirror the shipped config/*.php; where inline fallbacks had drifted
 * (app.debug, view.cache.expiry, view.cache.use_*), the config/*.php value wins.
 */

return [

    'app' => [
        'env'                   => 'production',
        'debug'                 => false,
        'url'                   => 'http://localhost',
        // Prefix applied to a controller named as a bare string in a route
        // ("PostController@index"). Only that form consults it — a route giving
        // the class itself resolves without it — so an application keeping
        // controllers elsewhere overrides this and nothing else changes.
        'controllers_namespace' => 'App\\Http\\Controllers\\',
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

    'logging' => [
        // 'file' writes to storage/logs; 'stderr' and 'stdout' write to the
        // process's own streams, which is how a container platform collects
        // logs — a file inside a container goes away with the container.
        'channel'   => 'file',
        // Overrides the default file path when the channel is 'file'.
        'path'      => null,
        // Rotate the file once it reaches this size; 0 disables rotation, and
        // a stream channel ignores it.
        'max_bytes' => 5_242_880,
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
        'batching' => [
            'table' => 'job_batches',
        ],
    ],

    'session' => [
        'driver'   => 'native',
        'lifetime' => 120,
        'cookie'   => 'nitro_session',
        'files'    => null,
        // Used by the 'redis' driver: the connection under database.redis to
        // write to, and the prefix its keys carry. Null takes the default one.
        'connection' => null,
        'prefix'     => 'nitro:session:',
        // Used by the 'database' driver.
        'table'      => 'sessions',
        // Odds that a request sweeps expired sessions once its response has
        // been sent: 2 in 100. Set the first number to 0 to never sweep.
        'lottery'  => [2, 100],
        // Files one sweep may delete. Bounded so the cost does not grow with
        // the backlog and a worker is never held up reading a large directory.
        'sweep_limit' => 100,
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
