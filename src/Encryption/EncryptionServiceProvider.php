<?php

namespace Nitro\Encryption;

use Nitro\Encryption\Contracts\Encrypter as EncrypterContract;
use Nitro\Foundation\Contracts\ConfigRepository;
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
        $this->container->singleton('encrypter', function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            $key          = (string) $config->get('app.key', '');
            $cipher       = (string) $config->get('app.cipher', 'aes-256-cbc');
            $previousKeys = (array) $config->get('app.previous_keys', []);

            $encrypter = new Encrypter($this->parseKey($key), $cipher);

            if ($previousKeys !== []) {
                $encrypter->previousKeys(array_map([$this, 'parseKey'], $previousKeys));
            }

            return $encrypter;
        });

        $this->container->alias('encrypter', Encrypter::class);
        $this->container->alias('encrypter', EncrypterContract::class);
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
