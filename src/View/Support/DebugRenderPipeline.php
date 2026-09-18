<?php

namespace Nitro\View\Support;

/**
 * Development-only render instrumentation.
 *
 * Records the nesting of render calls and the metadata each carries, so a
 * layout that resolves to the wrong sections can be read back as a trace.
 * Disabled by default and gated at every call site.
 */
class DebugRenderPipeline
{
    /** @var array<int, string> */
    private static array $log = [];

    /** Current nesting depth, used for indentation. */
    private static int $depth = 0;

    /** Whether instrumentation is recording. */
    private static bool $enabled = false;

    /**
     * Turn instrumentation on and discard any previous trace.
     */
    public static function enable(): void
    {
        self::$enabled = true;
        self::$log = [];
        self::$depth = 0;
    }

    /**
     * Turn instrumentation off, keeping the trace recorded so far.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Determine whether instrumentation is on.
     *
     * Call sites check this before building their context arrays, so a request
     * with instrumentation off pays one static bool read.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Record entering a call and increase the nesting depth.
     *
     * @param array<string, mixed> $context
     */
    public static function enter(string $method, array $context = []): void
    {
        if (!self::$enabled) return;

        self::$log[] = self::indent() . "▶ {$method}" . self::formatContext($context);
        self::$depth++;
    }

    /**
     * Record leaving a call and decrease the nesting depth.
     *
     * @param array<string, mixed> $context
     */
    public static function exit(string $method, array $context = []): void
    {
        if (!self::$enabled) return;

        self::$depth = max(0, self::$depth - 1);
        self::$log[] = self::indent() . "◀ {$method}" . self::formatContext($context);
    }

    /**
     * Record a one-line note at the current depth.
     */
    public static function note(string $message): void
    {
        if (!self::$enabled) return;

        self::$log[] = self::indent() . "• {$message}";
    }

    /**
     * The indentation for the current nesting depth.
     */
    private static function indent(): string
    {
        return str_repeat('    ', self::$depth);
    }

    /**
     * Render a context array as a trailing parenthesised list.
     *
     * @param array<string, mixed> $context
     */
    private static function formatContext(array $context): string
    {
        if (empty($context)) return '';

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = "{$key}=" . (is_array($value) ? json_encode($value) : $value);
        }
        return ' (' . implode(', ', $parts) . ')';
    }

    /**
     * Get the trace recorded so far.
     */
    public static function dump(): string
    {
        return implode("\n", self::$log);
    }

    /**
     * Write the trace to a file, defaulting to the application's log directory.
     */
    public static function save(?string $path = null): void
    {
        $path ??= function_exists('storage_path')
            ? storage_path('logs/render_trace.log')
            : sys_get_temp_dir() . '/render_trace.log';

        file_put_contents($path, self::dump());
    }
}
