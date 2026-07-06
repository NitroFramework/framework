<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\EloquentUserProvider;
use Nitro\Auth\Exceptions\AuthConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * EloquentUserProvider verification/rehash paths (which operate on a passed
 * Authenticatable and need no DB). retrieveBy* require a live model + query
 * builder and are covered by the model/DB integration tests.
 */
class EloquentUserProviderTest extends TestCase
{
    private function provider(): EloquentUserProvider
    {
        // Any existing class satisfies the constructor's class_exists guard;
        // validateCredentials/rehash never touch the model itself.
        return new EloquentUserProvider(\stdClass::class);
    }

    private function user(string $hash): Authenticatable
    {
        return new class($hash) implements Authenticatable {
            public function __construct(private string $hash) {}
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return $this->hash; }
        };
    }

    public function test_constructor_rejects_a_missing_model(): void
    {
        $this->expectException(AuthConfigurationException::class);
        new EloquentUserProvider('App\\Models\\DoesNotExist');
    }

    public function test_valid_password_passes(): void
    {
        $user = $this->user(password_hash('secret', PASSWORD_DEFAULT));
        $this->assertTrue($this->provider()->validateCredentials($user, ['password' => 'secret']));
    }

    public function test_wrong_password_fails(): void
    {
        $user = $this->user(password_hash('secret', PASSWORD_DEFAULT));
        $this->assertFalse($this->provider()->validateCredentials($user, ['password' => 'nope']));
    }

    public function test_empty_password_or_hash_fails(): void
    {
        $user = $this->user(password_hash('secret', PASSWORD_DEFAULT));
        $this->assertFalse($this->provider()->validateCredentials($user, ['password' => '']));
        $this->assertFalse($this->provider()->validateCredentials($user, []));

        $noHash = $this->user('');
        $this->assertFalse($this->provider()->validateCredentials($noHash, ['password' => 'secret']));
    }

    public function test_rehash_when_forced_rewrites_the_password(): void
    {
        $user = new class(password_hash('secret', PASSWORD_DEFAULT)) implements Authenticatable {
            public array $updated = [];
            public function __construct(private string $hash) {}
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return $this->hash; }
            public function getAuthPasswordName(): string { return 'password'; }
            public function update(array $attrs): bool { $this->updated = $attrs; return true; }
        };

        $this->provider()->rehashPasswordIfRequired($user, ['password' => 'secret'], force: true);

        $this->assertArrayHasKey('password', $user->updated);
        $this->assertTrue(password_verify('secret', $user->updated['password']));
    }

    public function test_rehash_is_a_noop_without_an_update_method(): void
    {
        // A user that can't persist must not error (SessionGuard calls this on
        // every successful login).
        $user = $this->user(password_hash('secret', PASSWORD_DEFAULT));
        $this->provider()->rehashPasswordIfRequired($user, ['password' => 'secret'], force: true);

        $this->addToAssertionCount(1); // reaching here without a TypeError is the assertion
    }
}
