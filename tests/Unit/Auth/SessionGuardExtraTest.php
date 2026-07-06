<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\SessionGuard;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * SessionGuard paths not covered by SessionGuardTest: loginUsingId (valid +
 * unknown id), validate() without logging in, and user() memoization.
 */
class SessionGuardExtraTest extends TestCase
{
    private function user(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable {
            public function __construct(private int $id) {}
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
            public function getAuthPassword(): string { return password_hash('secret', PASSWORD_DEFAULT); }
        };
    }

    private function provider(array $byId): UserProvider
    {
        return new class($byId) implements UserProvider {
            public int $retrieveByIdCalls = 0;
            public function __construct(private array $byId) {}
            public function retrieveById(mixed $id): ?Authenticatable
            {
                $this->retrieveByIdCalls++;
                return $this->byId[$id] ?? null;
            }
            public function retrieveByCredentials(array $c): ?Authenticatable
            {
                return $this->byId[$c['id'] ?? null] ?? null;
            }
            public function validateCredentials(Authenticatable $u, array $c): bool
            {
                return password_verify((string) ($c['password'] ?? ''), $u->getAuthPassword());
            }
        };
    }

    private function guard(UserProvider $provider): SessionGuard
    {
        $store = new Store('nitro_session', new ArraySessionHandler());
        $store->start();
        return new SessionGuard($provider, $store);
    }

    public function test_login_using_id_logs_in_a_known_user(): void
    {
        $user = $this->user(5);
        $guard = $this->guard($this->provider([5 => $user]));

        $returned = $guard->loginUsingId(5);

        $this->assertSame($user, $returned);
        $this->assertTrue($guard->check());
        $this->assertSame(5, $guard->id());
    }

    public function test_login_using_id_returns_null_for_unknown_id(): void
    {
        $guard = $this->guard($this->provider([]));

        $this->assertNull($guard->loginUsingId(999));
        $this->assertFalse($guard->check());
    }

    public function test_validate_checks_credentials_without_logging_in(): void
    {
        $user = $this->user(1);
        $guard = $this->guard($this->provider([1 => $user]));

        $this->assertTrue($guard->validate(['id' => 1, 'password' => 'secret']));
        $this->assertFalse($guard->validate(['id' => 1, 'password' => 'wrong']));
        // validate() must not authenticate the session.
        $this->assertFalse($guard->check());
    }

    public function test_user_is_memoized_after_first_resolution(): void
    {
        $user = $this->user(3);
        $provider = $this->provider([3 => $user]);
        $guard = $this->guard($provider);
        $guard->loginUsingId(3);

        $callsAfterLogin = $provider->retrieveByIdCalls;
        $guard->user();
        $guard->user();

        // login() already set the user in memory, so user() shouldn't re-query.
        $this->assertSame($callsAfterLogin, $provider->retrieveByIdCalls);
    }
}
