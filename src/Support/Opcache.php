<?php

namespace Nitro\Support;

/**
 * Check for opcache and act on it; every method does nothing where opcache is unavailable.
 */
final class Opcache
{
    /** The answer for this process, which cannot change once PHP has started. */
    private static ?bool $available = null;

    /** Determine whether opcache is loaded and switched on for this SAPI. */
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

    /** Determine whether opcache already holds bytecode for a file. */
    public static function isCached(string $path): bool
    {
        return self::available() && (bool) @opcache_is_script_cached($path);
    }

    /**
     * Compile a file into opcache without running it.
     *
     * @return bool Whether it compiled, false for a syntax error or when opcache is unavailable.
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

    /**
     * Drop all bytecode held by this process's opcache.
     *
     * @return bool Whether it was reset.
     */
    public static function reset(): bool
    {
        return self::available() && @opcache_reset();
    }
}
