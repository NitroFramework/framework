<?php

namespace Nitro\Support;

use ParseError;

/**
 * Opcache helpers (after Nitro's Support\Opcache): check for opcache and act on it; every
 * method is a no-op where opcache is unavailable for the current SAPI.
 */
final class Opcache
{
    /** The answer for this process, which cannot change once PHP has started. */
    private static ?bool $available = null;

    /** Whether opcache is loaded and switched on for this SAPI. */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (! function_exists('opcache_compile_file')) {
            return self::$available = false;
        }

        $setting = PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable';

        return self::$available = filter_var(ini_get($setting), FILTER_VALIDATE_BOOL);
    }

    /** Whether opcache already holds bytecode for a file. */
    public static function isCached(string $path): bool
    {
        return self::available() && (bool) @opcache_is_script_cached($path);
    }

    /**
     * Compile a file into opcache without running it.
     *
     * @return bool Whether it compiled (false for a syntax error or when opcache is unavailable).
     */
    public static function compile(string $path): bool
    {
        return self::available() && (bool) @opcache_compile_file($path);
    }

    /** Drop a file's bytecode, so a replaced or deleted file is not served from memory. */
    public static function invalidate(string $path): void
    {
        if (self::available()) {
            @opcache_invalidate($path, true);
        }
    }

    /** Drop all bytecode held by this process's opcache (the CLI's, not php-fpm's). */
    public static function reset(): bool
    {
        return self::available() && @opcache_reset();
    }

    /** Whether compiled bytecode is also written to disk (opcache.file_cache), shared across processes. */
    public static function fileCache(): ?string
    {
        $dir = ini_get('opcache.file_cache');

        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    /**
     * Whether a PHP file parses, without executing it. Uses opcache when available (which also
     * primes it), otherwise the tokenizer in parse mode.
     */
    public static function lint(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        if (self::available()) {
            return self::compile($path);
        }

        try {
            token_get_all((string) file_get_contents($path), TOKEN_PARSE);

            return true;
        } catch (ParseError) {
            return false;
        }
    }
}
