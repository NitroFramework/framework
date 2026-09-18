<?php

namespace Nitro\Session;

use Nitro\Encryption\Contracts\Encrypter;
use Nitro\Encryption\DecryptException;
use SessionHandlerInterface;

/**
 * A session store whose payload is encrypted before it reaches the handler.
 *
 * A payload that cannot be decrypted is treated as an empty session rather
 * than an error, so a rotated application key logs users out instead of
 * breaking every request.
 */
class EncryptedStore extends Store
{
    public function __construct(
        string $name,
        SessionHandlerInterface $handler,
        protected Encrypter $encrypter,
        ?string $id = null,
        string $serialization = 'php',
    ) {
        parent::__construct($name, $handler, $id, $serialization);
    }

    protected function prepareForUnserialize(string $data): string
    {
        try {
            return $this->encrypter->decryptString($data);
        } catch (DecryptException) {
            return $this->serialization === 'json' ? json_encode([]) : serialize([]);
        }
    }

    protected function prepareForStorage(string $data): string
    {
        return $this->encrypter->encryptString($data);
    }

    /**
     * Get the encrypter used for the payload.
     */
    public function getEncrypter(): Encrypter
    {
        return $this->encrypter;
    }
}
