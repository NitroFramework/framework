<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'NitroPHP'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => env('APP_URL', 'https://plainphp.test'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Key
    |--------------------------------------------------------------------------
    */
    'key' => env('APP_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Controller Namespace
    |--------------------------------------------------------------------------
    |
    | This namespace is used by the router when resolving controller strings.
    | For example, 'UserController@index' will resolve to 
    | 'App\Controllers\UserController'.
    |
    */
    'controllers_namespace' => 'App\\Controllers\\',

    /*
    |--------------------------------------------------------------------------
    | Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded during
    | the application bootstrap process.
    |
    */
    'providers' => [
        
        /*
         * Application Service Providers
         * Add your custom providers here
         */
        // Application service providers are added here by your app (the
        // skeleton ships its own config/app.php). This is the framework's
        // default config, so only framework feature providers are listed.

        Nitro\Livewire\LivewireServiceProvider::class,
        Nitro\Blaze\BlazeServiceProvider::class,
    ],



];
