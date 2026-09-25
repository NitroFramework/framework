<?php

namespace Nitro\Foundation\Providers;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\AuthManager;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\StatefulGuard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\EloquentUserProvider;
use Nitro\Auth\Middleware\Authenticate;
use Nitro\Auth\Middleware\AuthenticateWithBasicAuth;
use Nitro\Auth\Middleware\Authorize;
use Nitro\Auth\Middleware\EnsureEmailIsVerified;
use Nitro\Auth\Middleware\RedirectIfAuthenticated;
use Nitro\Auth\Middleware\RequirePassword;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Auth\Passwords\TokenRepository;
use Nitro\Auth\SessionGuard;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\ThrottleRequests;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Routing\Contracts\RouterInterface as Router;

/**
 * Register the authentication services and their middleware.
 */
class AuthServiceProvider extends ServiceProvider
{
    /** Register the user provider, guard, password broker and gate. */
    public function register(): void
    {
        $this->registerUserProvider();
        $this->registerGuard();
        $this->registerPasswordBroker();
        $this->registerGate();
    }

    /** Register where users are looked up from. */
    protected function registerUserProvider(): void
    {
        $this->container->singleton(UserProvider::class, function ($container) {
            return new EloquentUserProvider(
                (string) $container->resolve(ConfigRepository::class)->get('auth.model', 'App\\Models\\User'),
            );
        });
    }

    /**
     * Register the auth manager under 'auth', and the default guard under the guard contracts.
     *
     * Scoped, because the manager holds the request's session and signed-in user.
     */
    protected function registerGuard(): void
    {
        $this->container->scoped('auth', function ($container) {
            return new AuthManager(
                config: $container->resolve(ConfigRepository::class),
                resolver: $container->resolve(ClassResolver::class),
                sessionResolver: static fn () => $container->resolve('session'),
                events: $container->has('events') ? $container->resolve('events') : null,
            );
        });

        $this->container->alias('auth', AuthManager::class);

        $this->container->scoped(SessionGuard::class, static fn ($container) => $container->resolve('auth')->guard());
        $this->container->alias(SessionGuard::class, Guard::class);
        $this->container->alias(SessionGuard::class, StatefulGuard::class);
    }

    /** Register the password-reset token repository and broker. */
    protected function registerPasswordBroker(): void
    {
        $this->container->singleton(TokenRepository::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new TokenRepository(
                (string) $config->get('auth.passwords.table', 'password_reset_tokens'),
                (int) $config->get('auth.passwords.expire', 3600),
            );
        });

        $this->container->singleton(PasswordBroker::class, function ($container) {
            return new PasswordBroker(
                $container->resolve(UserProvider::class),
                $container->resolve(TokenRepository::class),
            );
        });
    }

    /**
     * Register the authorization gate.
     *
     * Shared, so policies registered at boot apply to every check; the user is resolved per check.
     */
    protected function registerGate(): void
    {
        $this->container->singleton(Gate::class, function ($container) {
            return new Gate(
                $container->resolve(ClassResolver::class),
                static fn () => $container->has('auth')
                    ? $container->resolve('auth')->user()
                    : null,
            );
        });
    }

    /** Register the auth and throttle middleware aliases and their order. */
    public function boot(Router $router, Kernel $kernel): void
    {
        $router->aliasMiddleware('auth', Authenticate::class);
        $router->aliasMiddleware('auth.basic', AuthenticateWithBasicAuth::class);
        $router->aliasMiddleware('can', Authorize::class);
        $router->aliasMiddleware('guest', RedirectIfAuthenticated::class);
        $router->aliasMiddleware('verified', EnsureEmailIsVerified::class);
        $router->aliasMiddleware('password.confirm', RequirePassword::class);
        $router->aliasMiddleware('throttle', ThrottleRequests::class);

        $this->declareMiddlewareOrder($kernel);
    }

    /**
     * Run the session-reading auth middleware after the CSRF check, whatever order a route lists them in.
     */
    protected function declareMiddlewareOrder(Kernel $kernel): void
    {
        $after = VerifyCsrfToken::class;

        foreach ([Authenticate::class, RedirectIfAuthenticated::class, RequirePassword::class, EnsureEmailIsVerified::class] as $middleware) {
            $kernel->addMiddlewarePriority($middleware, $after);
            $after = $middleware;
        }
    }
}
