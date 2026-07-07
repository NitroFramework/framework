<?php

namespace Nitro\Encryption;

use Nitro\Encryption\Contracts\Encrypter as EncrypterContract;
use RuntimeException;

/**
 * Authenticated encryption. A payload is a base64-encoded JSON envelope
 * {iv, value, mac, tag}: CBC ciphers carry an HMAC-SHA256 MAC, GCM ciphers are
 * AEAD and carry their authentication tag. Previous (rotated) keys are tried on
 * decrypt so a key change doesn't invalidate existing payloads.
 */
class Encrypter implements EncrypterContract
{
    /** Supported ciphers → key size (bytes) + whether the mode is AEAD. */
    private const SUPPORTED_CIPHERS = [
        'aes-128-cbc' => ['size' => 16, 'aead' => false],
        'aes-256-cbc' => ['size' => 32, 'aead' => false],
        'aes-128-gcm' => ['size' => 16, 'aead' => true],
        'aes-256-gcm' => ['size' => 32, 'aead' => true],
    ];

    /** @var string Raw encryption key. */
    protected string $key;

    /** @var array<int, string> Previous/legacy keys tried on decrypt failure. */
    protected array $previousKeys = [];

    protected string $cipher;

    public function __construct(string $key, string $cipher = 'aes-256-cbc')
    {
        if (! static::supported($key, $cipher)) {
            $ciphers = implode(', ', array_keys(self::SUPPORTED_CIPHERS));
            throw new RuntimeException("Unsupported cipher or incorrect key length. Supported ciphers are: {$ciphers}.");
        }

        $this->key = $key;
        $this->cipher = $cipher;
    }

    /** Is this key length valid for the given cipher? */
    public static function supported(string $key, string $cipher): bool
    {
        $c = self::SUPPORTED_CIPHERS[strtolower($cipher)] ?? null;

        return $c !== null && mb_strlen($key, '8bit') === $c['size'];
    }

    /** A fresh random key sized for the given cipher. */
    public static function generateKey(string $cipher): string
    {
        return random_bytes(self::SUPPORTED_CIPHERS[strtolower($cipher)]['size'] ?? 32);
    }

    public function encrypt(mixed $value, bool $serialize = true): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(strtolower($this->cipher)));

        $tag = '';
        $value = openssl_encrypt(
            $serialize ? serialize($value) : $value,
            strtolower($this->cipher),
            $this->key,
            0,
            $iv,
            $tag
        );

        if ($value === false) {
            throw new EncryptException('Could not encrypt the data.');
        }

        $iv = base64_encode($iv);
        $tag = base64_encode($tag ?? '');

        $mac = self::SUPPORTED_CIPHERS[strtolower($this->cipher)]['aead']
            ? '' // AEAD ciphers authenticate via the tag, not a separate MAC.
            : $this->hash($iv, $value, $this->key);

        $json = json_encode(['iv' => $iv, 'value' => $value, 'mac' => $mac, 'tag' => $tag], JSON_UNESCAPED_SLASHES);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new EncryptException('Could not encrypt the data.');
        }

        return base64_encode($json);
    }

    public function encryptString(string $value): string
    {
        return $this->encrypt($value, false);
    }

    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        $payload = $this->getJsonPayload($payload);

        $iv = base64_decode($payload['iv']);
        $tag = empty($payload['tag']) ? null : base64_decode($payload['tag']);

        $this->ensureTagIsValid($tag);

        $decrypted = false;

        if ($this->shouldValidateMac()) {
            $validKey = null;
            foreach ($this->getAllKeys() as $key) {
                if ($validKey === null && $this->validMacForKey($payload, $key)) {
                    $validKey = $key;
                }
            }
            if ($validKey === null) {
                throw new DecryptException('The MAC is invalid.');
            }
            $decrypted = openssl_decrypt($payload['value'], strtolower($this->cipher), $validKey, 0, $iv, $tag ?? '');
        } else {
            foreach ($this->getAllKeys() as $key) {
                $decrypted = openssl_decrypt($payload['value'], strtolower($this->cipher), $key, 0, $iv, $tag ?? '');
                if ($decrypted !== false) {
                    break;
                }
            }
        }

        if ($decrypted === false) {
            throw new DecryptException('Could not decrypt the data.');
        }

        return $unserialize ? unserialize($decrypted) : $decrypted;
    }

    public function decryptString(string $payload): string
    {
        return $this->decrypt($payload, false);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** The current key plus any previous keys, tried in order on decrypt. */
    public function getAllKeys(): array
    {
        return [$this->key, ...$this->previousKeys];
    }

    public function getPreviousKeys(): array
    {
        return $this->previousKeys;
    }

    /** Register previous/legacy keys used to decrypt payloads after a key rotation. */
    public function previousKeys(array $keys): static
    {
        foreach ($keys as $key) {
            if (! static::supported($key, $this->cipher)) {
                $ciphers = implode(', ', array_keys(self::SUPPORTED_CIPHERS));
                throw new RuntimeException("Unsupported cipher or incorrect key length. Supported ciphers are: {$ciphers}.");
            }
        }

        $this->previousKeys = $keys;

        return $this;
    }

    /** Does this value look like one of our encryption payloads? */
    public static function appearsEncrypted(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    // ─── internals ────────────────────────────────────────

    protected function hash(string $iv, string $value, string $key): string
    {
        return hash_hmac('sha256', $iv . $value, $key);
    }

    protected function getJsonPayload(string $payload): array
    {
        $payload = json_decode(base64_decode($payload), true);

        if (! $this->validPayload($payload)) {
            throw new DecryptException('The payload is invalid.');
        }

        return $payload;
    }

    protected function validPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $item) {
            if (! isset($payload[$item]) || ! is_string($payload[$item])) {
                return false;
            }
        }

        if (isset($payload['tag']) && ! is_string($payload['tag'])) {
            return false;
        }

        return strlen(base64_decode($payload['iv'], true)) === openssl_cipher_iv_length(strtolower($this->cipher));
    }

    protected function validMacForKey(array $payload, string $key): bool
    {
        return hash_equals($this->hash($payload['iv'], $payload['value'], $key), $payload['mac']);
    }

    protected function ensureTagIsValid(?string $tag): void
    {
        $aead = self::SUPPORTED_CIPHERS[strtolower($this->cipher)]['aead'];

        if ($aead && strlen((string) $tag) !== 16) {
            throw new DecryptException('Could not decrypt the data.');
        }

        if (! $aead && is_string($tag)) {
            throw new DecryptException('Unable to use tag because the cipher algorithm does not support AEAD.');
        }
    }

    protected function shouldValidateMac(): bool
    {
        return ! self::SUPPORTED_CIPHERS[strtolower($this->cipher)]['aead'];
    }
}
