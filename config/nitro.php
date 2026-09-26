<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Compilation
    |--------------------------------------------------------------------------
    |
    | What `php artisan optimize` compiles, on top of Laravel's own caches. Each
    | compiled cache behaves exactly as Laravel does; turn one off to run that
    | part on Laravel's code as it is.
    |
    | eloquent: Laravel's Model and each model's boot plan (eloquent:cache), so
    | models boot without reflecting on every request.
    |
    | urls: each named route's URL template (route:cache), so route() fills it
    | in without building the route or matching its parameters.
    |
    */

    'compile' => [
        'eloquent' => env('NITRO_COMPILE_ELOQUENT', true),
        'urls' => env('NITRO_COMPILE_URLS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent
    |--------------------------------------------------------------------------
    |
    | Where eloquent:cache looks for models. Models elsewhere still work; they
    | boot through Laravel's own code.
    |
    */

    'eloquent' => [
        'paths' => [
            app_path(),
        ],
    ],

];
