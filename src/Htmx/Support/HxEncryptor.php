<?php

namespace Nitro\Htmx\Support;

/**
 * Encrypts the hx-vals payload that round-trips through the client.
 *
 * Uses AES-256-GCM — authenticated encryption: the GCM tag means a tampered or
 * bit-flipped ciphertext fails to decrypt (returns []), rather than silently
 * decrypting to attacker-chosen data. (The previous AES-128-CTR had no MAC, so
 * the payload was malleable.)
 */
class HxEncryptor
{
    /** 32-byte AES-256 key derived from the app key. */
    private string $key;

    public function __construct(
        private bool $enabled,
        private string $appKey,
    ) {
        $this->key = hash('sha256', $this->appKey !== '' ? $this->appKey : 'nitro-insecure-dev-key', true);
    }

    public function encrypt(array $vals): string
    {
        if (! $this->enabled) {
            return base64_encode(json_encode($vals));
        }

        $iv  = random_bytes(12); // 96-bit GCM nonce
        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) json_encode($vals),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        // iv(12) . tag(16) . ciphertext — the tag authenticates both.
        return rtrim(base64_encode($iv . $tag . $ciphertext), '=');
    }

    public function decrypt(string $payload): array
    {
        if (! $this->enabled) {
            return json_decode((string) base64_decode($payload), true) ?? [];
        }

        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < 28) {
            return [];
        }

        $iv         = substr($data, 0, 12);
        $tag        = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $json = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '');

        // false = authentication failed (tampered payload or wrong key) → reject.
        if ($json === false) {
            return [];
        }

        return json_decode($json, true) ?? [];
    }
}
