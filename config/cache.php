<?php

return [

    // compile-time cache paths (views, config, routes, etc.)
    'paths' => [
        'compiled'  => 'compiled.php',
        'config'    => 'config.php',
        'routes'    => 'routes/routes.php',
        'bootstrap' => 'bootstrap.php',
        'views'     => 'views',
    ],

    // runtime data cache
    'default' => env('CACHE_DRIVER', 'file'),
    'prefix'  => env('CACHE_PREFIX', 'nitro_cache:'),

    'stores' => [
        'file'  => [
            'driver' => 'file',
            'path'   => storage_path('cache/data'),
            'ttl'    => 3600,
        ],
        'redis' => [
            'driver'   => 'redis',
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_CACHE_DB', 1),
            'ttl'      => 3600,
        ],
        'array' => [
            'driver' => 'array',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],

];