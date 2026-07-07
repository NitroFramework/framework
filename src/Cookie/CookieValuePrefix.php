<?php

namespace Nitro\Cookie;

/**
 * Binds a decrypted cookie value to the name it was stored under. Without this
 * an attacker who can set one cookie could move a valid encrypted value to a
 * different cookie name; the HMAC prefix makes the value name-specific.
 */
class CookieValuePrefix
{
    /** The prefix for a cookie name under the given key ('<hmac>|'). */
    public static function create(string $cookieName, string $key): string
    {
        return hash_hmac('sha1', $cookieName . 'v2', $key) . '|';
    }

    /** Strip the prefix (40 hex chars + '|') from a value. */
    public static function remove(string $cookieValue): string
    {
        return substr($cookieValue, 41);
    }

    /**
     * Return the value without its prefix if it carries a valid one for the
     * given name (trying each key), or null when the prefix doesn't match.
     */
    public static function validate(string $cookieName, string $cookieValue, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (str_starts_with($cookieValue, static::create($cookieName, $key))) {
                return static::remove($cookieValue);
            }
        }

        return null;
    }
}
