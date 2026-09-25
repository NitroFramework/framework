<?php

/**
 * Framework configuration defaults.
 *
 * The application's config/*.php is merged over these, so every key the framework
 * reads resolves. Application-specific values, such as credentials and the app key,
 * have no default here.
 */

return [

    'app' => [
        'env'                   => 'production',
        'debug'                 => false,
        'url'                   => 'http://localhost',
        /** Namespace for a controller named as a bare string, such as "PostController@index". */
        'controllers_namespace' => 'App\\Http\\Controllers\\',
        'providers'             => [],
        /** Proxy addresses whose X-Forwarded-* headers are trusted, or '*' for all; empty trusts none. */
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
        /** The channel used when none is named; an unlisted channel throws rather than falling back. */
        'default' => 'stack',

        'channels' => [
            /** A file, and the process's standard error for platforms that collect it. */
            'stack' => [
                'driver'            => 'stack',
                'channels'          => ['single', 'stderr'],
                'ignore_exceptions' => true,
            ],

            'single' => [
                'driver'    => 'single',
                'path'      => null,
                'max_bytes' => 5_242_880,
                'level'     => 'debug',
            ],

            'daily' => [
                'driver' => 'daily',
                'path'   => null,
                'days'   => 14,
                'level'  => 'debug',
            ],

            'stderr' => [
                'driver' => 'stream',
                'stream' => 'php://stderr',
                'level'  => 'debug',
            ],

            'stdout' => [
                'driver' => 'stream',
                'stream' => 'php://stdout',
                'level'  => 'debug',
            ],

            'errorlog' => [
                'driver' => 'errorlog',
                'level'  => 'debug',
            ],

            'null' => [
                'driver' => 'null',
            ],
        ],
    ],

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
        /** The redis driver's connection under database.redis, null for the default, and its key prefix. */
        'connection' => null,
        'prefix'     => 'nitro:session:',
        /** The database driver's table. */
        'table'      => 'sessions',
        /** Odds that a request sweeps expired sessions after its response; [0, 100] never sweeps. */
        'lottery'  => [2, 100],
        /** Most expired sessions one sweep deletes. */
        'sweep_limit' => 100,
    ],

    'view' => [
        /** Template extensions, tried in order within each views directory. */
        'extensions'   => ['blade.php', 'md'],
        'debug_render' => false,
        'markdown' => [
            /** Whether raw HTML in a document is kept rather than escaped. */
            'allow_html'  => false,
            'hard_breaks' => false,
            /** Attributes added to links that stay in the application. */
            'link_attributes' => ['wire:navigate' => true],
            /** The URL an absolute link must match to count as internal. */
            'base_url'        => null,
            /** Whether a link to a place on the same page counts as internal. */
            'fragments'       => false,
        ],
        'cache' => [
            'enabled'     => true,
            'expiry'      => 0,
            /** Prime compiled views into opcache; takes effect only where opcache is available. */
            'use_opcache' => true,
            'use_locks'   => false,
        ],
    ],

];
