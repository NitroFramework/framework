<?php

namespace Nitro\Foundation\Providers;

use Nitro\Http\Kernel;
use Nitro\Http\Middleware\ThrottleRequests;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\EloquentUserProvider;
use Nitro\Auth\SessionGuard;
use Nitro\Auth\Middleware\Authenticate;
use Nitro\Auth\Middleware\EnsureEmailIsVerified;
use Nitro\Auth\Middleware\RedirectIfAuthenticated;
use Nitro\Auth\Middleware\RequirePassword;
use Nitro\Auth\Access\Gate;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Auth\Passwords\TokenRepository;
use Nitro\Routing\Contracts\RouterInterface as Router;

/** Registers authentication services and middleware. */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The user provider is stateless — a single shared instance is fine.
        // It reads the model class from config('auth.model').
        $this->container->singleton(UserProvider::class, function ($container) {
            return new EloquentUserProvider(
                (string) config('auth.model', 'App\\Models\\User'),
            );
        });

        // Scoped (not singleton): the manager holds the request's session and a
        // per-request user cache, so it must be rebuilt each worker request — it
        // declares that lifecycle here rather than via a central reset list.
        $this->container->scoped('auth', function ($container) {
            return new SessionGuard(
                $container->resolve(UserProvider::class),
                $container->resolve('session'),
            );
        });

        $this->container->alias(SessionGuard::class, 'auth');
        $this->container->alias(Guard::class, 'auth');

        // Password-reset stack. Both are stateless given their config, so shared
        // singletons are fine. The broker reuses the same UserProvider as auth.
        $this->container->singleton(TokenRepository::class, function ($container) {
            return new TokenRepository(
                (string) config('auth.passwords.table', 'password_reset_tokens'),
                (int) config('auth.passwords.expire', 3600),
            );
        });

        $this->container->singleton(PasswordBroker::class, function ($container) {
            return new PasswordBroker(
                $container->resolve(UserProvider::class),
                $container->resolve(TokenRepository::class),
            );
        });

        /*
         * The authorization gate. Shared, because a policy registered in one
         * provider's boot() has to be visible to every later check — a
         * per-resolution Gate would answer from an empty policy map.
         *
         * Given the container and a resolver for the current user rather than
         * the user itself: the gate outlives any one request's authentication,
         * and asking for the user at construction would pin whoever was signed
         * in when the first check ran.
         */
        $this->container->singleton(Gate::class, function ($container) {
            return new Gate(
                $container,
                static fn () => $container->has('auth')
                    ? $container->resolve('auth')->user()
                    : null,
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
        $router = $this->container->resolve(Router::class);

        $router->aliasMiddleware('auth', Authenticate::class);
        $router->aliasMiddleware('guest', RedirectIfAuthenticated::class);
        $router->aliasMiddleware('verified', EnsureEmailIsVerified::class);
        $router->aliasMiddleware('password.confirm', RequirePassword::class);

        // General HTTP throttle (config-driven via throttle.*). Registered here
        // since this is the framework's middleware-alias hub; login lockout uses
        // the RateLimiter directly in the controller.
        $router->aliasMiddleware('throttle', ThrottleRequests::class);

        $this->declareMiddlewareOrder();
    }

    /**
     * Auth's middleware all read the session, so they must run after it opens.
     *
     * Anchored after the CSRF check rather than straight after the session: a
     * forged request should be refused before anything looks up who is making
     * it, and the token itself is read off the session either way.
     *
     * Declared from this side rather than listed on the Kernel, which names no
     * feature layer's middleware. Without it, ->middleware(['auth', 'web'])
     * checks a session that has not started yet and finds nobody logged in.
     */
    protected function declareMiddlewareOrder(): void
    {
        $kernel = $this->container->resolve(Kernel::class);

        $after = VerifyCsrfToken::class;

        foreach ([Authenticate::class, RedirectIfAuthenticated::class, RequirePassword::class, EnsureEmailIsVerified::class] as $middleware) {
            $kernel->addMiddlewarePriority($middleware, $after);
            $after = $middleware;
        }
    }
}
