<?php

namespace Nitro\Facades;

/**
 * Lang facade — translated strings.
 *
 *   Lang::get('messages.welcome', ['name' => 'Ada']);
 *   Lang::choice('messages.items', 3);
 *
 * @method static string get(string $key, array $replace = [], ?string $locale = null)
 * @method static string choice(string $key, int $number, array $replace = [], ?string $locale = null)
 * @method static bool has(string $key, ?string $locale = null)
 * @method static string getLocale()
 * @method static \Nitro\Translation\Translator setLocale(string $locale)
 * @method static string getFallback()
 * @method static \Nitro\Translation\Translator setFallback(string $locale)
 * @method static bool isLocale(string $locale)
 * @method static \Nitro\Translation\Translator addLines(array $lines, string $locale, string $group)
 */
class Lang extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'translator';
    }
}
