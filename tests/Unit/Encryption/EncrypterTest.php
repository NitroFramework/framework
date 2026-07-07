<?php

namespace Tests\Unit\Encryption;

use Nitro\Encryption\DecryptException;
use Nitro\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncrypterTest extends TestCase
{
    private function key(int $bytes = 32): string
    {
        return str_repeat('a', $bytes);
    }

    public function test_cbc_round_trips_a_string(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-cbc');
        $payload = $e->encryptString('the launch codes');

        $this->assertNotSame('the launch codes', $payload);
        $this->assertSame('the launch codes', $e->decryptString($payload));
    }

    public function test_gcm_round_trips_a_string(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-gcm');
        $this->assertSame('secret', $e->decryptString($e->encryptString('secret')));
    }

    public function test_serializes_arbitrary_values(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-cbc');
        $value = ['id' => 42, 'roles' => ['admin', 'user'], 'flag' => true];

        $this->assertSame($value, $e->decrypt($e->encrypt($value)));
    }

    public function test_each_encryption_uses_a_fresh_iv(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-cbc');
        $this->assertNotSame($e->encryptString('x'), $e->encryptString('x'));
    }

    public function test_tampered_cbc_payload_is_rejected_by_mac(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-cbc');
        $payload = $e->encryptString('trusted');

        // Flip a byte inside the base64 envelope.
        $raw = base64_decode($payload);
        $raw = substr_replace($raw, $raw[50] === 'A' ? 'B' : 'A', 50, 1);
        $tampered = base64_encode($raw);

        $this->expectException(DecryptException::class);
        $e->decryptString($tampered);
    }

    public function test_tampered_gcm_payload_is_rejected_by_tag(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-gcm');
        $payload = $e->encryptString('trusted');
        $raw = base64_decode($payload);
        $tampered = base64_encode(substr_replace($raw, $raw[60] === 'A' ? 'B' : 'A', 60, 1));

        $this->expectException(DecryptException::class);
        $e->decryptString($tampered);
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $payload = (new Encrypter($this->key(), 'aes-256-cbc'))->encryptString('x');

        $this->expectException(DecryptException::class);
        (new Encrypter(str_repeat('b', 32), 'aes-256-cbc'))->decryptString($payload);
    }

    public function test_previous_key_decrypts_after_rotation(): void
    {
        $old = str_repeat('o', 32);
        $new = str_repeat('n', 32);

        $payload = (new Encrypter($old, 'aes-256-cbc'))->encryptString('legacy');

        $rotated = (new Encrypter($new, 'aes-256-cbc'))->previousKeys([$old]);
        $this->assertSame('legacy', $rotated->decryptString($payload));
    }

    public function test_rejects_bad_key_length(): void
    {
        $this->expectException(RuntimeException::class);
        new Encrypter(str_repeat('a', 10), 'aes-256-cbc');
    }

    public function test_appears_encrypted(): void
    {
        $e = new Encrypter($this->key(), 'aes-256-cbc');
        $this->assertTrue(Encrypter::appearsEncrypted($e->encryptString('x')));
        $this->assertFalse(Encrypter::appearsEncrypted('plain text'));
    }
}
