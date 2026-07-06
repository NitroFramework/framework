<?php

return [
    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Primary path where template files are stored. In the future, this could
    | be extended to support multiple paths or namespaced views.
    |
    */
    'paths' => resource_path('views'),

    /*
    |--------------------------------------------------------------------------
    | Template File Extension
    |--------------------------------------------------------------------------
    |
    | The file extension for your Blade templates. Default is 'blade.php'
    | but you could use 'html.php', 'tpl.php', etc.
    |
    */
    'extension' => 'blade.php',

    /*
    |--------------------------------------------------------------------------
    | Compiled View Cache Settings
    |--------------------------------------------------------------------------
    |
    | Controls how compiled views are cached. In production, caching should
    | always be enabled. In development, you might disable it or use
    | aggressive invalidation.
    |
    */
    'cache' => [
        // Enable/disable view compilation caching
        'enabled' => true,

        // Where compiled views are stored
        'path' => storage_path('cache/views'),

        // Cache expiry in seconds (0 = never expire based on time, only on file changes)
        'expiry' => 0,

        // Use PHP OpCode cache for additional performance
        'use_opcache' => false,

        // Use file locks during compilation to prevent race conditions
        'use_locks' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, provides detailed error messages and warnings.
    | Should be false in production.
    |
    */
    'debug_render' => false,
];