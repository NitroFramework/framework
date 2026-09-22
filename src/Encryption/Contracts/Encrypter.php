<?php

namespace Nitro\Encryption\Contracts;

/**
 * Contract for the encrypter — implemented by Encrypter and resolved as the
 * 'encrypter' service behind the Crypt facade.
 */
interface Encrypter
{
    /** Encrypt the given value (PHP-serialized unless $serialize is false). */
    public function encrypt(mixed $value, bool $serialize = true): string;

    /** Decrypt the given payload (unserialized unless $unserialize is false). */
    public function decrypt(string $payload, bool $unserialize = true): mixed;

    /** Encrypt a string without serialization. */
    public function encryptString(string $value): string;

    /** Decrypt a string without unserialization. */
    public function decryptString(string $payload): string;

    /** The current encryption key (raw bytes). */
    public function getKey(): string;

    /**
     * The current key plus any previous ones, in the order decrypt tries them.
     *
     * EncryptCookies needs the full set to read a cookie written before a key
     * rotation, so it belongs on the contract rather than only the concrete.
     *
     * @return array<int, string>
     */
    public function getAllKeys(): array;
}
