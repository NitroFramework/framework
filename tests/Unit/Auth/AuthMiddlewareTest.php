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
use Nitro\Http\RedirectResponse;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The auth middleware, exercised against a REAL SessionGuard over an in-memory
 * session so guest/authenticated/verified states are genuine (Authenticate uses
 * setIntendedUrl, which isn't on the Guard contract — a mock would miss it).
 */
class AuthMiddlewareTest extends TestCase
{
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

    private function provider(?Authenticatable $user): UserProvider
    {
        return new class($user) implements UserProvider {
            public function __construct(private ?Authenticatable $user) {}
            public function retrieveById(mixed $id): ?Authenticatable { return $this->user; }
            public function retrieveByCredentials(array $c): ?Authenticatable { return $this->user; }
            public function validateCredentials(Authenticatable $u, array $c): bool { return true; }
        };
    }

    /** @return array{0: SessionGuard, 1: Store} */
    private function guard(?Authenticatable $user): array
    {
        $store = $this->session();
        $guard = new SessionGuard($this->provider($user), $store);
        if ($user !== null) {
            $guard->login($user);
        }
        return [$guard, $store];
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
        $config->method('get')->willReturnCallback(fn($k, $d = null) => $map[$k] ?? $d);
        return $config;
    }

    private function next(): callable
    {
        return fn(Request $r) => Response::html('passed');
    }

    private function req(string $path = '/dashboard'): Request
    {
        return new Request('GET', $path);
    }

    // ─── Authenticate ────────────────────────────────────────────────────────

    public function test_authenticate_redirects_guests_to_login(): void
    {
        [$guard] = $this->guard(null);
        $res = (new Authenticate($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertInstanceOf(RedirectResponse::class, $res);
        $this->assertSame('/login', $res->header('Location'));
    }

    public function test_authenticate_passes_authenticated_users(): void
    {
        [$guard] = $this->guard($this->user());
        $res = (new Authenticate($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertSame('passed', $res->getContent());
    }

    // ─── RedirectIfAuthenticated (guest) ──────────────────────────────────────

    public function test_guest_middleware_redirects_authenticated_users(): void
    {
        [$guard] = $this->guard($this->user());
        $res = (new RedirectIfAuthenticated($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertInstanceOf(RedirectResponse::class, $res);
        $this->assertSame('/dashboard', $res->header('Location'));
    }

    public function test_guest_middleware_passes_guests(): void
    {
        [$guard] = $this->guard(null);
        $res = (new RedirectIfAuthenticated($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertSame('passed', $res->getContent());
    }

    // ─── EnsureEmailIsVerified ────────────────────────────────────────────────

    public function test_unverified_user_is_redirected(): void
    {
        [$guard] = $this->guard($this->user(verified: false));
        $res = (new EnsureEmailIsVerified($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertInstanceOf(RedirectResponse::class, $res);
        $this->assertSame('/verify-email', $res->header('Location'));
    }

    public function test_verified_user_passes(): void
    {
        [$guard] = $this->guard($this->user(verified: true));
        $res = (new EnsureEmailIsVerified($guard, $this->config()))->handle($this->req(), $this->next());

        $this->assertSame('passed', $res->getContent());
    }

    // ─── RequirePassword ──────────────────────────────────────────────────────

    public function test_require_password_passes_when_recently_confirmed(): void
    {
        [$guard, $store] = $this->guard($this->user());
        $store->put('auth.password_confirmed_at', time());

        $res = (new RequirePassword($guard, $this->config(), $store))->handle($this->req(), $this->next());
        $this->assertSame('passed', $res->getContent());
    }

    public function test_require_password_redirects_when_stale(): void
    {
        [$guard, $store] = $this->guard($this->user());
        $store->put('auth.password_confirmed_at', time() - 20000); // older than 10800

        $res = (new RequirePassword($guard, $this->config(), $store))->handle($this->req(), $this->next());
        $this->assertInstanceOf(RedirectResponse::class, $res);
        $this->assertSame('/confirm-password', $res->header('Location'));
    }
}
