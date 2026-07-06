<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Enabled
    |---------------------------------------------------------------------------
    |
    | Master switch for the Blaze layer. When off, every component renders
    | through Nitro's normal view pipeline unchanged.
    |
    */

    'enabled' => (bool) filter_var(env('BLAZE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |---------------------------------------------------------------------------
    | Optimized Directories
    |---------------------------------------------------------------------------
    |
    | Plain-template (anonymous) components under these directories are compiled
    | into direct PHP functions, bypassing the per-render component pipeline.
    | You can also opt a single component in with an @blaze marker at its top.
    | Additional directories may be registered at runtime with
    | Blaze::optimize()->in($dir) from a service provider.
    |
    */

    'directories' => [
        base_path('resources/views/components'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Compiled Cache Path
    |---------------------------------------------------------------------------
    |
    | Where the compiled component functions are written.
    |
    */

    'cache_path' => storage_path('cache/blaze'),

];
