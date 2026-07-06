<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session Driver
    |--------------------------------------------------------------------------
    |
    | Backend for the session Store. Supported: "native" (default — PHP's own
    | $_SESSION, interoperates with the CSRF/HTMX layer and classic SAPIs),
    | "file" (cookie + file handler, worker-safe), "array" (in-memory, tests).
    | Redis/database drivers can be added in SessionManager without touching
    | anything that depends on the Store.
    |
    */
    'driver' => env('SESSION_DRIVER', 'native'),

    /*
    |--------------------------------------------------------------------------
    | Lifetime (minutes)
    |--------------------------------------------------------------------------
    */
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    /*
    |--------------------------------------------------------------------------
    | Cookie Name
    |--------------------------------------------------------------------------
    */
    'cookie' => env('SESSION_COOKIE', 'nitro_session'),

    /*
    |--------------------------------------------------------------------------
    | File Store Location
    |--------------------------------------------------------------------------
    |
    | Where the "file" driver writes session payloads. Defaults (in the service
    | provider) to storage/framework/sessions when unset.
    |
    */
    'files' => env('SESSION_FILES', null),

    /*
    |--------------------------------------------------------------------------
    | Cookie Attributes (file/array drivers)
    |--------------------------------------------------------------------------
    |
    | Used when a non-native driver manages its own session cookie. `secure`
    | null means "auto" (only over HTTPS). The native driver uses PHP's own
    | session cookie settings and ignores these.
    |
    */
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN', null),
    'secure' => env('SESSION_SECURE_COOKIE', null),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'lax'),

];
