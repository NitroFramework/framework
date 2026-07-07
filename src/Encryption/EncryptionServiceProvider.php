<?php

namespace Nitro\Encryption;

use Nitro\Encryption\Contracts\Encrypter as EncrypterContract;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the Encrypter as a shared 'encrypter' service, keyed off the
 * application key (config('app.key')) and cipher (config('app.cipher')).
 * Nothing here is hardcoded — key, cipher and any previous (rotated) keys all
 * come from config.
 */
class EncryptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('encrypter', function () {
            $config = [
                'key'    => (string) config('app.key', ''),
                'cipher' => (string) config('app.cipher', 'aes-256-cbc'),
                'previous_keys' => (array) config('app.previous_keys', []),
            ];

            $encrypter = new Encrypter($this->parseKey($config['key']), $config['cipher']);

            if ($config['previous_keys'] !== []) {
                $encrypter->previousKeys(array_map([$this, 'parseKey'], $config['previous_keys']));
            }

            return $encrypter;
        });

        $this->container->alias(Encrypter::class, 'encrypter');
        $this->container->alias(EncrypterContract::class, 'encrypter');
    }

    /**
     * Turn the configured key into raw bytes. Keys minted by `key:generate`
     * carry a "base64:" prefix; a raw key is used as-is.
     */
    protected function parseKey(string $key): string
    {
        if ($key === '') {
            throw new MissingAppKeyException();
        }

        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }
}
