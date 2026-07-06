<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\SessionGuard;
use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Session\NativeSession;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight Authenticatable used by the tests — no model/DB required.
 */
class FakeUser implements Authenticatable
{
    public function __construct(
        private int|string $id,
        private string $hash = '',
    ) {}

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthPassword(): string { return $this->hash; }
}

/**
 * In-memory UserProvider stub: retrieval is wired by the test, verification
 * uses the real password_verify so credential tests exercise actual hashing.
 */
class FakeProvider implements UserProvider
{
    /** @var array<int|string, Authenticatable> */
    public array $byId = [];
    public ?Authenticatable $byCredentials = null;

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return $this->byId[$identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return $this->byCredentials;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return password_verify((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }
}

/**
 * Each test runs in its own process so $_SESSION and session state don't bleed
 * across tests. The manager is driven through a real NativeSession bound to
 * $_SESSION, so these also exercise the session wiring end to end.
 */
class SessionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** @return array{0: SessionGuard, 1: FakeProvider} */
    protected function make(): array
    {
        $session = new NativeSession('test_session');
        $session->start();
        $provider = new FakeProvider();
        return [new SessionGuard($provider, $session), $provider];
    }

    #[RunInSeparateProcess]
    public function test_login_stores_id_in_session(): void
    {
        [$auth] = $this->make();
        $auth->login(new FakeUser(42));

        $this->assertSame(42, $auth->id());
        $this->assertSame(42, $_SESSION['_auth_id']);
    }

    #[RunInSeparateProcess]
    public function test_login_regenerates_session_id_to_prevent_fixation(): void
    {
        [$auth] = $this->make();
        $initialId = session_id();
        $this->assertNotSame('', $initialId);

        $auth->login(new FakeUser(1));

        $this->assertNotSame($initialId, session_id(), 'Session ID must rotate on login.');
    }

    #[RunInSeparateProcess]
    public function test_logout_clears_session_and_rotates_id(): void
    {
        [$auth] = $this->make();
        $auth->login(new FakeUser(7));
        $afterLoginId = session_id();

        $auth->logout();

        $this->assertNull($auth->id());
        $this->assertNotSame($afterLoginId, session_id(), 'Session ID must rotate on logout.');
    }

    #[RunInSeparateProcess]
    public function test_id_returns_null_when_not_authenticated(): void
    {
        [$auth] = $this->make();
        $this->assertNull($auth->id());
        $this->assertTrue($auth->guest());
        $this->assertFalse($auth->check());
    }

    #[RunInSeparateProcess]
    public function test_check_true_after_login(): void
    {
        [$auth] = $this->make();
        $auth->login(new FakeUser(9));
        $this->assertTrue($auth->check());
    }

    #[RunInSeparateProcess]
    public function test_user_is_resolved_through_provider(): void
    {
        [$auth, $provider] = $this->make();
        $user = new FakeUser(15);
        $provider->byId[15] = $user;

        $auth->login($user);

        $this->assertSame($user, $auth->user());
    }

    #[RunInSeparateProcess]
    public function test_attempt_logs_in_on_valid_credentials(): void
    {
        [$auth, $provider] = $this->make();
        $provider->byCredentials = new FakeUser(3, password_hash('s3cret', PASSWORD_DEFAULT));

        $this->assertTrue($auth->attempt(['email' => 'a@b.c', 'password' => 's3cret']));
        $this->assertTrue($auth->check());
        $this->assertSame(3, $auth->id());
    }

    #[RunInSeparateProcess]
    public function test_attempt_fails_on_wrong_password(): void
    {
        [$auth, $provider] = $this->make();
        $provider->byCredentials = new FakeUser(3, password_hash('s3cret', PASSWORD_DEFAULT));

        $this->assertFalse($auth->attempt(['email' => 'a@b.c', 'password' => 'wrong']));
        $this->assertTrue($auth->guest());
    }

    #[RunInSeparateProcess]
    public function test_attempt_fails_when_no_user_matches(): void
    {
        [$auth, $provider] = $this->make();
        $provider->byCredentials = null;

        $this->assertFalse($auth->attempt(['email' => 'missing@b.c', 'password' => 'whatever']));
        $this->assertTrue($auth->guest());
    }

    #[RunInSeparateProcess]
    public function test_validate_password_checks_current_user(): void
    {
        [$auth] = $this->make();
        $auth->login(new FakeUser(3, password_hash('s3cret', PASSWORD_DEFAULT)));

        $this->assertTrue($auth->validatePassword('s3cret'));
        $this->assertFalse($auth->validatePassword('wrong'));
    }

    #[RunInSeparateProcess]
    public function test_validate_password_is_false_for_guests(): void
    {
        [$auth] = $this->make();
        $this->assertFalse($auth->validatePassword('anything'));
    }

    #[RunInSeparateProcess]
    public function test_intended_url_round_trip(): void
    {
        [$auth] = $this->make();
        $auth->setIntendedUrl('/dashboard');
        $this->assertSame('/dashboard', $auth->getIntendedUrl());
        $this->assertNull($auth->getIntendedUrl(), 'Intended URL should be cleared after read.');
    }
}
