<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\SessionGuard;
use Nitro\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that session-backed auth persists across requests on the
 * FILE driver (it always worked on native; before the cookie-wiring fix the
 * file/array drivers minted a new id every request, so a logged-in user was
 * forgotten immediately). Two SessionManagers over the same files dir + the
 * carried session id simulate two HTTP requests.
 */
class AuthSessionPersistenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__) . '/storage/tests_auth_sessions';
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store()
    {
        return (new SessionManager([
            'driver' => 'file', 'cookie' => 'nitro_session', 'lifetime' => 120, 'files' => $this->dir,
        ]))->driver();
    }

    private function user(int $id, string $plain = 'secret'): Authenticatable
    {
        return new class($id, password_hash($plain, PASSWORD_DEFAULT)) implements Authenticatable {
            public function __construct(private int|string $id, private string $hash) {}
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
            public function getAuthPassword(): string { return $this->hash; }
        };
    }

    private function provider(Authenticatable $user): UserProvider
    {
        return new class($user) implements UserProvider {
            public function __construct(private Authenticatable $user) {}
            public function retrieveById(mixed $id): ?Authenticatable
            {
                return $id === $this->user->getAuthIdentifier() ? $this->user : null;
            }
            public function retrieveByCredentials(array $c): ?Authenticatable
            {
                return $this->user;
            }
            public function validateCredentials(Authenticatable $u, array $c): bool
            {
                return password_verify((string) ($c['password'] ?? ''), $u->getAuthPassword());
            }
        };
    }

    public function test_logged_in_user_persists_to_the_next_request(): void
    {
        $user = $this->user(42);
        $provider = $this->provider($user);

        // Request 1: log in, persist. login() rotates the id (fixation defence).
        $s1 = $this->store();
        $s1->start();
        (new SessionGuard($provider, $s1))->login($user);
        $id = $s1->getId();
        $s1->save();

        // Request 2: same cookie id → still authenticated.
        $s2 = $this->store();
        $s2->setId($id);
        $s2->start();
        $guard2 = new SessionGuard($provider, $s2);

        $this->assertTrue($guard2->check());
        $this->assertSame(42, $guard2->id());
        $this->assertSame($user, $guard2->user());
    }

    public function test_attempt_then_persist(): void
    {
        $user = $this->user(7, 'hunter2');
        $provider = $this->provider($user);

        $s1 = $this->store();
        $s1->start();
        $guard1 = new SessionGuard($provider, $s1);
        $this->assertTrue($guard1->attempt(['email' => 'a@b.co', 'password' => 'hunter2']));
        $id = $s1->getId();
        $s1->save();

        $s2 = $this->store();
        $s2->setId($id);
        $s2->start();
        $this->assertTrue((new SessionGuard($provider, $s2))->check());
    }

    public function test_logout_does_not_persist(): void
    {
        $user = $this->user(42);
        $provider = $this->provider($user);

        $s1 = $this->store();
        $s1->start();
        $guard1 = new SessionGuard($provider, $s1);
        $guard1->login($user);
        $guard1->logout();
        $afterLogoutId = $s1->getId();
        $s1->save();

        // The post-logout (rotated) id has no auth id stored.
        $s2 = $this->store();
        $s2->setId($afterLogoutId);
        $s2->start();
        $this->assertFalse((new SessionGuard($provider, $s2))->check());
    }

    public function test_session_id_rotates_on_login(): void
    {
        $s1 = $this->store();
        $s1->start();
        $before = $s1->getId();

        (new SessionGuard($this->provider($this->user(1)), $s1))->login($this->user(1));

        $this->assertNotSame($before, $s1->getId(), 'id must rotate to prevent fixation');
    }
}
