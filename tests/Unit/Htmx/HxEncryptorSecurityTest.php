<?php

namespace Tests\Unit\Htmx;

use Nitro\Htmx\Support\HxEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * The hx-vals encryptor uses authenticated encryption (AES-256-GCM): a tampered
 * or wrong-key payload must fail to decrypt (return []) rather than yield
 * attacker-chosen data. This is the property AES-CTR-without-a-MAC lacked.
 */
class HxEncryptorSecurityTest extends TestCase
{
    public function test_round_trips_when_enabled(): void
    {
        $enc = new HxEncryptor(true, 'my-secret-app-key');
        $payload = $enc->encrypt(['id' => 42, 'role' => 'user']);

        $this->assertSame(['id' => 42, 'role' => 'user'], $enc->decrypt($payload));
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $enc = new HxEncryptor(true, 'my-secret-app-key');
        $payload = $enc->encrypt(['role' => 'user']);

        // Flip a byte in the middle of the token.
        $pos = intdiv(strlen($payload), 2);
        $payload[$pos] = $payload[$pos] === 'A' ? 'B' : 'A';

        $this->assertSame([], $enc->decrypt($payload), 'tampered payload must not decrypt');
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $payload = (new HxEncryptor(true, 'key-one'))->encrypt(['secret' => 1]);

        $this->assertSame([], (new HxEncryptor(true, 'key-two'))->decrypt($payload));
    }

    public function test_disabled_mode_round_trips(): void
    {
        $enc = new HxEncryptor(false, 'k');
        $this->assertSame(['a' => 1], $enc->decrypt($enc->encrypt(['a' => 1])));
    }

    public function test_garbage_input_returns_empty(): void
    {
        $enc = new HxEncryptor(true, 'k');
        $this->assertSame([], $enc->decrypt('not-a-valid-payload!!!'));
        $this->assertSame([], $enc->decrypt(''));
    }
}
