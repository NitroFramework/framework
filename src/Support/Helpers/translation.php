<?php

use Nitro\Translation\Translator;

if (! function_exists('trans')) {
    /**
     * Translate a key, or get the translator itself when given none.
     *
     * @param array<string, mixed> $replace
     */
    function trans(?string $key = null, array $replace = [], ?string $locale = null): mixed
    {
        $translator = app('translator');

        if ($key === null) {
            return $translator;
        }

        return $translator->get($key, $replace, $locale);
    }
}

if (! function_exists('__')) {
    /**
     * Translate a key.
     *
     * @param array<string, mixed> $replace
     */
    function __(?string $key = null, array $replace = [], ?string $locale = null): mixed
    {
        return trans($key, $replace, $locale);
    }
}

if (! function_exists('trans_choice')) {
    /**
     * Translate a key, picking the plural form that matches the count.
     *
     * @param \Countable|array<mixed>|int $number
     * @param array<string, mixed>        $replace
     */
    function trans_choice(string $key, mixed $number, array $replace = [], ?string $locale = null): string
    {
        return app('translator')->choice($key, $number, $replace, $locale);
    }
}

if (! function_exists('lang_path')) {
    /**
     * Get the path to the application's language files.
     */
    function lang_path(string $path = ''): string
    {
        return app('paths')->base('lang' . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path));
    }
}
