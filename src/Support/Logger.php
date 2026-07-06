<?php

namespace Nitro\Support;

/**
 * Minimal file logger exposing the eight PSR-3 severity levels.
 *
 * Not a formal Psr\Log\LoggerInterface implementation (no psr/log dependency),
 * but the level method names and (level, message, context) signature mirror it,
 * so swapping in a PSR-3 logger later is mechanical. Appends are atomic
 * (LOCK_EX) and the file is size-rotated so it can't grow without bound.
 */
class Logger
{
    private static ?string $logPath = null;

    /** Track which directories we've already ensured exist for the lifetime of the process. */
    private static array $verifiedDirs = [];

    /** Rotate the log once it reaches this many bytes (0 disables rotation). */
    private static int $maxBytes = 5_242_880; // 5 MB

    public static function setPath(string $path): void
    {
        // Avoid redundant is_dir() / mkdir() syscalls when the path doesn't
        // actually change between calls in the same process (worker mode,
        // multi-bootstrap test setups, etc.).
        if (self::$logPath === $path) {
            return;
        }
        self::$logPath = $path;

        $dir = dirname($path);
        if (!isset(self::$verifiedDirs[$dir]) && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::$verifiedDirs[$dir] = true;
    }

    /** Set the rotation threshold in bytes (0 disables rotation). */
    public static function setMaxBytes(int $bytes): void
    {
        self::$maxBytes = max(0, $bytes);
    }

    /** System is unusable. */
    public static function emergency(string $message, array $context = []): void
    {
        self::log('emergency', $message, $context);
    }

    /** Action must be taken immediately. */
    public static function alert(string $message, array $context = []): void
    {
        self::log('alert', $message, $context);
    }

    /** Critical conditions. */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /** Runtime errors that don't require immediate action but should be logged. */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /** Exceptional occurrences that are not errors. */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /** Normal but significant events. */
    public static function notice(string $message, array $context = []): void
    {
        self::log('notice', $message, $context);
    }

    /** Interesting events. */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /** Detailed debug information. */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * Write a log line at an arbitrary level. Level is normalized to upper case
     * in the output; context is appended as JSON when non-empty.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$logPath) {
            return; // Silent fail if no log path set
        }

        self::rotateIfNeeded();

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = "[{$timestamp}] " . strtoupper($level) . ": {$message}{$contextStr}\n";

        file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Single-generation size rotation: when the active log reaches the
     * threshold, move it to "<path>.1" (overwriting any previous archive) so
     * the next write starts a fresh file. Threshold is approximate — a write
     * may push slightly past it before rotation kicks in, which is fine.
     */
    private static function rotateIfNeeded(): void
    {
        if (self::$maxBytes <= 0) {
            return;
        }

        // filesize() is stat-cached; within a long-running worker that writes
        // many lines this would otherwise keep returning the stale (small)
        // size and never rotate. Clear the cache for this path first.
        clearstatcache(true, self::$logPath);
        $size = @filesize(self::$logPath);
        if ($size === false || $size < self::$maxBytes) {
            return;
        }

        @rename(self::$logPath, self::$logPath . '.1');
    }
}
