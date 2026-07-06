<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Auth\Passwords\TokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * In-memory token repository (no DB): records the email->plaintext token the
 * broker mints, and validates against it.
 */
class ArrayTokenRepository extends TokenRepository
{
    /** @var array<string,string> */
    public array $tokens = [];

    public function create(string $email): string
    {
        return $this->tokens[$email] = 'tok-' . $email;
    }

    public function exists(string $email, string $token): bool
    {
        return isset($this->tokens[$email]) && hash_equals($this->tokens[$email], $token);
    }

    public function delete(string $email): void
    {
        unset($this->tokens[$email]);
    }
}

/** UserProvider stub: a single known user, matched by email. */
class StubUserProvider implements UserProvider
{
    public function __construct(private ?Authenticatable $user, private string $email) {}

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return $this->user;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return ($credentials['email'] ?? null) === $this->email ? $this->user : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return true;
    }
}

class PasswordBrokerTest extends TestCase
{
    /** A minimal Authenticatable — the broker treats users as opaque. */
    private function fakeUser(): Authenticatable
    {
        return new class implements Authenticatable {
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return ''; }
        };
    }

    private function broker(?Authenticatable $user, string $email): array
    {
        $tokens = new ArrayTokenRepository();
        $broker = new PasswordBroker(new StubUserProvider($user, $email), $tokens);
        return [$broker, $tokens];
    }

    public function test_send_reset_link_mints_token_and_invokes_callback(): void
    {
        $user = $this->fakeUser();
        [$broker] = $this->broker($user, 'a@b.c');

        $captured = null;
        $status = $broker->sendResetLink(['email' => 'a@b.c'], function ($u, $token) use (&$captured) {
            $captured = [$u, $token];
        });

        $this->assertSame(PasswordBroker::RESET_LINK_SENT, $status);
        $this->assertSame($user, $captured[0]);
        $this->assertSame('tok-a@b.c', $captured[1]);
    }

    public function test_send_reset_link_unknown_user_returns_invalid_user(): void
    {
        [$broker] = $this->broker($this->fakeUser(), 'a@b.c');

        $called = false;
        $status = $broker->sendResetLink(['email' => 'nobody@x.y'], function () use (&$called) {
            $called = true;
        });

        $this->assertSame(PasswordBroker::INVALID_USER, $status);
        $this->assertFalse($called, 'Callback must not run for an unknown user.');
    }

    public function test_reset_with_valid_token_runs_callback_and_consumes_token(): void
    {
        $user = $this->fakeUser();
        [$broker, $tokens] = $this->broker($user, 'a@b.c');
        $token = $tokens->create('a@b.c');

        $newPassword = null;
        $status = $broker->reset(
            ['email' => 'a@b.c', 'token' => $token, 'password' => 'new-secret'],
            function ($u, $password) use (&$newPassword) {
                $newPassword = $password;
            },
        );

        $this->assertSame(PasswordBroker::PASSWORD_RESET, $status);
        $this->assertSame('new-secret', $newPassword);
        $this->assertFalse($tokens->exists('a@b.c', $token), 'Token must be consumed after reset.');
    }

    public function test_reset_with_bad_token_returns_invalid_token(): void
    {
        $user = $this->fakeUser();
        [$broker, $tokens] = $this->broker($user, 'a@b.c');
        $tokens->create('a@b.c');

        $called = false;
        $status = $broker->reset(
            ['email' => 'a@b.c', 'token' => 'wrong-token', 'password' => 'x'],
            function () use (&$called) {
                $called = true;
            },
        );

        $this->assertSame(PasswordBroker::INVALID_TOKEN, $status);
        $this->assertFalse($called);
    }

    public function test_reset_unknown_user_returns_invalid_user(): void
    {
        [$broker] = $this->broker($this->fakeUser(), 'a@b.c');

        $status = $broker->reset(
            ['email' => 'nobody@x.y', 'token' => 't', 'password' => 'x'],
            fn () => null,
        );

        $this->assertSame(PasswordBroker::INVALID_USER, $status);
    }
}
