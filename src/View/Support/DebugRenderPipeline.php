<?php

namespace Nitro\View\Support;

/**
 * Dev-only render instrumentation — records per-view timing/metadata when enabled.
 */
class DebugRenderPipeline
{
    private static array $log = [];
    private static int $depth = 0;
    private static bool $enabled = false;

    public static function enable(): void
    {
        self::$enabled = true;
        self::$log = [];
        self::$depth = 0;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Cheap inline check used by every call site to short-circuit BEFORE
     * building the context array. Reading a static bool is the fastest
     * thing PHP can do — once this returns false, none of the array-
     * literal or sprintf work in the caller runs.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function enter(string $method, array $context = []): void
    {
        if (!self::$enabled) return;

        self::$log[] = self::indent() . "▶ {$method}" . self::formatContext($context);
        self::$depth++;
    }

    public static function exit(string $method, array $context = []): void
    {
        if (!self::$enabled) return;

        self::$depth = max(0, self::$depth - 1);
        self::$log[] = self::indent() . "◀ {$method}" . self::formatContext($context);
    }

    public static function note(string $message): void
    {
        if (!self::$enabled) return;

        self::$log[] = self::indent() . "• {$message}";
    }

    private static function indent(): string
    {
        return str_repeat('    ', self::$depth);
    }

    private static function formatContext(array $context): string
    {
        if (empty($context)) return '';

        $parts = [];
        foreach ($context as $k => $v) {
            $parts[] = "{$k}=" . (is_array($v) ? json_encode($v) : $v);
        }
        return ' (' . implode(', ', $parts) . ')';
    }

    public static function dump(): string
    {
        return implode("\n", self::$log);
    }

    public static function save(string $path = 'C:/xampp/htdocs/PlainPHP/render_trace.log'): void
    {
        file_put_contents($path, self::dump());
    }
}
