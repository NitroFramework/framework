<?php

/*
|--------------------------------------------------------------------------
| Auth route names and paths (for reference when adding to routes)
|--------------------------------------------------------------------------
| Named routes to use: login, register, dashboard
| Controllers: App\Controllers\Auth\LoginController, RegisterController
|
| Example routes (add to config/routes.php):
|
|   $router->middleware('guest');
|   $router->get('/login', [\App\Controllers\Auth\LoginController::class, 'showLoginForm'])->name('login');
|   $router->post('/login', [\App\Controllers\Auth\LoginController::class, 'store']);
|   $router->get('/register', [\App\Controllers\Auth\RegisterController::class, 'showRegistrationForm'])->name('register');
|   $router->post('/register', [\App\Controllers\Auth\RegisterController::class, 'store']);
|   $router->middleware('auth');
|   $router->post('/logout', [\App\Controllers\Auth\LoginController::class, 'destroy'])->name('logout');
|   $router->get('/dashboard', fn() => view('dashboard.noinheritance', [...]))->name('dashboard');
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication model
    |--------------------------------------------------------------------------
    */
    'model' => \App\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Redirect paths (used by auth/guest middleware when route() is not used)
    |--------------------------------------------------------------------------
    */
    'redirects' => [
        'login'            => '/login',
        'register'         => '/register',
        'dashboard'        => '/dashboard',
        'home'             => '/',
        'verification'     => '/verify-email',
        'password_confirm' => '/confirm-password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset tokens
    |--------------------------------------------------------------------------
    | table  — where hashed reset tokens are stored.
    | expire — token lifetime in seconds (3600 = 1 hour).
    */
    'passwords' => [
        'table'  => 'password_reset_tokens',
        'expire' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password confirmation timeout (seconds)
    |--------------------------------------------------------------------------
    | How long a confirm-password stays valid before the 'password.confirm'
    | middleware asks again. 10800 = 3 hours (Laravel's default).
    */
    'password_timeout' => 10800,
];
