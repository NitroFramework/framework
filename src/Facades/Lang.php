<?php

namespace Nitro\Facades;

/**
 * Lang facade — translated strings.
 *
 *   Lang::get('messages.welcome', ['name' => 'Ada']);
 *   Lang::choice('messages.items', 3);
 *
 * @method static string|array get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true)
 * @method static string string(string $key, array $replace = [], ?string $locale = null, bool $fallback = true)
 * @method static array array(string $key, array $replace = [], ?string $locale = null, bool $fallback = true)
 * @method static string choice(string $key, mixed $number, array $replace = [], ?string $locale = null)
 * @method static bool has(string $key, ?string $locale = null, bool $fallback = true)
 * @method static bool hasForLocale(string $key, ?string $locale = null)
 * @method static string getLocale()
 * @method static string locale()
 * @method static \Nitro\Translation\Translator setLocale(string $locale)
 * @method static string getFallback()
 * @method static \Nitro\Translation\Translator setFallback(string $locale)
 * @method static bool isLocale(string $locale)
 * @method static \Nitro\Translation\Translator addLines(array $lines, string $locale, string $namespace = '*')
 * @method static \Nitro\Translation\Translator addNamespace(string $namespace, string $hint)
 * @method static array namespaces()
 * @method static \Nitro\Translation\Translator addPath(string $path)
 * @method static \Nitro\Translation\Translator addJsonPath(string $path)
 * @method static void load(string $namespace, string $group, string $locale)
 * @method static array parseKey(string $key)
 * @method static \Nitro\Translation\Translator handleMissingKeysUsing(?callable $callback)
 * @method static \Nitro\Translation\Translator determineLocalesUsing(?callable $callback)
 * @method static \Nitro\Translation\Translator stringable(string $class, ?callable $handler = null)
 * @method static \Nitro\Translation\MessageSelector getSelector()
 * @method static \Nitro\Translation\Translator setSelector(\Nitro\Translation\MessageSelector $selector)
 * @method static \Nitro\Translation\Contracts\Loader getLoader()
 * @method static \Nitro\Translation\Translator setLoaded(array $loaded)
 *
 * @see \Nitro\Translation\Translator
 */
class Lang extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'translator';
    }
}
