<?php

namespace Nitro\Facades;

/**
 * Crypt facade — authenticated encryption keyed off the application key.
 *
 *   Crypt::encryptString($secret);  Crypt::decryptString($payload);
 *   Crypt::encrypt(['any' => 'value']);  Crypt::decrypt($payload);
 *
 * @method static string encrypt(mixed $value, bool $serialize = true)
 * @method static mixed  decrypt(string $payload, bool $unserialize = true)
 * @method static string encryptString(string $value)
 * @method static string decryptString(string $payload)
 * @method static string getKey()
 */
class Crypt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'encrypter';
    }
}
