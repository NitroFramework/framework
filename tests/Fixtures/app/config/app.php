<?php

return [
    'name' => 'Nitro Tests',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    'aliases' => Illuminate\Support\Facades\Facade::defaultAliases()->merge([
        'Str' => Illuminate\Support\Str::class,
    ])->toArray(),
];
