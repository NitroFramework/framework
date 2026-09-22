<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\MustVerifyEmail;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Middleware\Authenticate;
use Nitro\Auth\Middleware\EnsureEmailIsVerified;
use Nitro\Auth\Middleware\RedirectIfAuthenticated;
use Nitro\Auth\Middleware\RequirePassword;
use Nitro\Auth\SessionGuard;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * What the auth middleware answer a client that cannot follow a redirect.
 *
 * Each of these used to send an HTML page to something asking for JSON. A
 * fetch() that follows a 302 to a login form parses the form as its response
 * and reports nothing an application can act on, so the status is the whole
 * message: 401 for unauthenticated, 403 for unverified, 423 for a stale
 * password confirmation.
 */
class AuthMiddlewareParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Authenticate::redirectUsing(null);
    }

    private function session(): Store
    {
        $store = new Store('nitro_session', new ArraySessionHandler());
        $store->start();

        return $store;
    }

    private function user(bool $verified = true): Authenticatable
    {
        return new class($verified) implements Authenticatable, MustVerifyEmail {
            public function __construct(private bool $verified) {}
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return ''; }
            public function hasVerifiedEmail(): bool { return $this->verified; }
            public function markEmailAsVerified(): bool { return $this->verified = true; }
            public function getEmailForVerification(): string { return 'a@b.co'; }
        };
    }

    private function guard(?Authenticatable $user): SessionGuard
    {
        $provider = new class($user) implements UserProvider {
            public function __construct(private ?Authenticatable $user) {}
            public function retrieveById(mixed $id): ?Authenticatable { return $this->user; }
            public function retrieveByCredentials(array $c): ?Authenticatable { return $this->user; }
            public function validateCredentials(Authenticatable $u, array $c): bool { return true; }
        };

        $guard = new SessionGuard($provider, $this->session());

        if ($user !== null) {
            $guard->login($user);
        }

        return $guard;
    }

    private function config(): ConfigRepository
    {
        $map = [
            'auth.redirects.login'            => '/login',
            'auth.redirects.dashboard'        => '/dashboard',
            'auth.redirects.verification'     => '/verify-email',
            'auth.redirects.password_confirm' => '/confirm-password',
            'auth.password_timeout'           => 10800,
        ];

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnCallback(static fn ($key, $default = null) => $map[$key] ?? $default);

        return $config;
    }

    private function next(): callable
    {
        return static fn (Request $request): Response => Response::html('passed');
    }

    private function json(string $path = '/dashboard'): Request
    {
        return new Request('GET', $path, ['accept' => 'application/json']);
    }

    // --- email verification -------------------------------------------------

    /** A JSON client is told what is wrong, not sent to a page. */
    public function test_an_unverified_json_client_gets_403(): void
    {
        $middleware = new EnsureEmailIsVerified($this->guard($this->user(false)), $this->config());

        $response = $middleware->handle($this->json(), $this->next());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('not verified', $response->getContent());
    }

    /**
     * A guest fails this middleware too.
     *
     * In a stack that runs `auth` first there is no such request, but without
     * the check a route protected by this one alone admits everybody who is
     * not signed in.
     */
    public function test_a_guest_does_not_pass_the_verified_check(): void
    {
        $middleware = new EnsureEmailIsVerified($this->guard(null), $this->config());

        $response = $middleware->handle(new Request('GET', '/dashboard'), $this->next());

        $this->assertSame('/verify-email', $response->header('Location'));
    }

    /** A route can name its own verification page. */
    public function test_the_verification_redirect_can_be_given_per_route(): void
    {
        $middleware = new EnsureEmailIsVerified($this->guard($this->user(false)), $this->config());

        $response = $middleware->handle(new Request('GET', '/dashboard'), $this->next(), '/confirm-your-email');

        $this->assertSame('/confirm-your-email', $response->header('Location'));
    }

    public function test_a_verified_user_passes(): void
    {
        $middleware = new EnsureEmailIsVerified($this->guard($this->user(true)), $this->config());

        $this->assertSame('passed', $middleware->handle($this->json(), $this->next())->getContent());
    }

    // --- password confirmation ----------------------------------------------

    /** 423 Locked: the client may have this once it proves who it is. */
    public function test_a_stale_confirmation_answers_423_for_json(): void
    {
        $session = $this->session();

        $middleware = new RequirePassword($this->guard($this->user()), $this->config(), $session);

        $response = $middleware->handle($this->json(), $this->next());

        $this->assertSame(423, $response->getStatusCode());
    }

    /** A fresh confirmation passes. */
    public function test_a_recent_confirmation_passes(): void
    {
        $session = $this->session();
        $session->put('auth.password_confirmed_at', time());

        $middleware = new RequirePassword($this->guard($this->user()), $this->config(), $session);

        $this->assertSame('passed', $middleware->handle($this->json(), $this->next())->getContent());
    }

    /**
     * The window can be set per route.
     *
     * An administrative action may want a shorter one than the application
     * default, without a second middleware to express it.
     */
    public function test_the_timeout_can_be_given_per_route(): void
    {
        $session = $this->session();
        $session->put('auth.password_confirmed_at', time() - 60);

        $middleware = new RequirePassword($this->guard($this->user()), $this->config(), $session);

        // Inside the application default, outside a 30-second window.
        $this->assertSame('passed', $middleware->handle($this->json(), $this->next())->getContent());
        $this->assertSame(423, $middleware->handle($this->json(), $this->next(), null, 30)->getStatusCode());
    }

    // --- redirect seams -----------------------------------------------------

    /** Where a signed-in visitor lands is an override point. */
    public function test_the_guest_middleware_redirect_can_be_overridden(): void
    {
        $middleware = new class($this->guard($this->user()), $this->config()) extends RedirectIfAuthenticated {
            protected function redirectTo(Request $request): string
            {
                return '/admin';
            }
        };

        $this->assertSame('/admin', $middleware->handle(new Request('GET', '/login'), $this->next())->header('Location'));
    }

    /** And the login redirect can be decided from the request. */
    public function test_the_login_redirect_can_be_decided_from_the_request(): void
    {
        Authenticate::redirectUsing(static fn (Request $request): string
            => str_starts_with($request->path(), '/admin') ? '/admin/login' : '/login');

        $middleware = new Authenticate($this->guard(null), $this->config());

        try {
            $middleware->handle(new Request('GET', '/admin/reports'), $this->next());
            $this->fail('a guest must not reach the route');
        } catch (\Nitro\Auth\Exceptions\AuthenticationException $exception) {
            $this->assertSame('/admin/login', $exception->redirectTo());
        }
    }
}
