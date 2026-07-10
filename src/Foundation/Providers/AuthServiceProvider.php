<?php

namespace Nitro\Foundation\Providers;

use Nitro\Http\Middleware\ThrottleRequests;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\EloquentUserProvider;
use Nitro\Auth\SessionGuard;
use Nitro\Auth\Middleware\Authenticate;
use Nitro\Auth\Middleware\EnsureEmailIsVerified;
use Nitro\Auth\Middleware\RedirectIfAuthenticated;
use Nitro\Auth\Middleware\RequirePassword;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Auth\Passwords\TokenRepository;
use Nitro\Routing\Router;

/** Registers authentication services and middleware. */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The user provider is stateless — a single shared instance is fine.
        // It reads the model class from config('auth.model').
        $this->container->singleton(UserProvider::class, function ($c) {
            return new EloquentUserProvider(
                (string) config('auth.model', 'App\\Models\\User'),
            );
        });

        // Scoped (not singleton): the manager holds the request's session and a
        // per-request user cache, so it must be rebuilt each worker request — it
        // declares that lifecycle here rather than via a central reset list.
        $this->container->scoped('auth', function ($c) {
            return new SessionGuard(
                $c->make(UserProvider::class),
                $c->make('session'),
            );
        });

        $this->container->alias(SessionGuard::class, 'auth');
        $this->container->alias(Guard::class, 'auth');

        // Password-reset stack. Both are stateless given their config, so shared
        // singletons are fine. The broker reuses the same UserProvider as auth.
        $this->container->singleton(TokenRepository::class, function ($c) {
            return new TokenRepository(
                (string) config('auth.passwords.table', 'password_reset_tokens'),
                (int) config('auth.passwords.expire', 3600),
            );
        });

        $this->container->singleton(PasswordBroker::class, function ($c) {
            return new PasswordBroker(
                $c->make(UserProvider::class),
                $c->make(TokenRepository::class),
            );
        });
    }

    /**
     * Register the auth route-middleware aliases on the Router. This is the seam
     * that keeps the core kernel from naming Auth: the alias map lives on the
     * Router (Laravel-style), and this feature provider wires its own entries.
     */
    public function boot(): void
    {
        $router = $this->container->make(Router::class);

        $router->aliasMiddleware('auth', Authenticate::class);
        $router->aliasMiddleware('guest', RedirectIfAuthenticated::class);
        $router->aliasMiddleware('verified', EnsureEmailIsVerified::class);
        $router->aliasMiddleware('password.confirm', RequirePassword::class);

        // General HTTP throttle (config-driven via throttle.*). Registered here
        // since this is the framework's middleware-alias hub; login lockout uses
        // the RateLimiter directly in the controller.
        $router->aliasMiddleware('throttle', ThrottleRequests::class);
    }
}
