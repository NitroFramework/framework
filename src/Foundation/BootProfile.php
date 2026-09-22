<?php

namespace Nitro\Foundation;

/**
 * Timing marks across a single php-fpm request.
 *
 * fpm pays for the whole boot on every request, so the question a profile has
 * to answer is which half the time is in — getting ready to serve, or serving.
 * Marks are taken at the points that split those: after each bootstrapper,
 * after the route is matched, and after the bytes have gone out.
 *
 * The baseline is `$_SERVER['REQUEST_TIME_FLOAT']`, set by PHP before any
 * application code runs, so autoloading and compilation are inside the first
 * measurement rather than invisible ahead of it.
 *
 * Off unless NITRO_PROFILE is set, and when off {@see mark()} is a bool test
 * and a return — a profiler that cost anything would change what it measures.
 */
final class BootProfile
{
    /** @var array<int, array{name: string, at: float}> */
    private static array $marks = [];

    private static ?bool $enabled = null;

    private static ?float $baseline = null;

    /**
     * Whether profiling is on for this process.
     *
     * Read from the environment directly rather than from config: the first
     * mark is taken before configuration is loaded.
     */
    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            $flag = $_SERVER['NITRO_PROFILE'] ?? $_ENV['NITRO_PROFILE'] ?? getenv('NITRO_PROFILE');

            self::$enabled = $flag !== false && $flag !== null
                && $flag !== '' && $flag !== '0' && $flag !== 'false';
        }

        return self::$enabled;
    }

    /** Turn profiling on or off explicitly, and drop any marks taken so far. */
    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
        self::$marks = [];
        self::$baseline = null;
    }

    /** Record the moment a named stage finished. */
    public static function mark(string $name): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$marks[] = ['name' => $name, 'at' => microtime(true)];
    }

    /** @var array<string, int> */
    private static array $counts = [];

    /**
     * Tally something that happens many times a request.
     *
     * A stage's duration says where the time went; a count says what was done
     * to spend it. Container resolutions are the case this exists for: the
     * number is the thing worth arguing about, and it is cheaper to count
     * them than to time each one.
     */
    public static function count(string $name, int $times = 1): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$counts[$name] = (self::$counts[$name] ?? 0) + $times;
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return self::$counts;
    }

    /**
     * When the request began, as PHP saw it — before the autoloader ran.
     */
    public static function baseline(): float
    {
        if (self::$baseline === null) {
            $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

            self::$baseline = is_numeric($start) ? (float) $start : microtime(true);
        }

        return self::$baseline;
    }

    /**
     * The marks, each with its total elapsed time and the time since the mark
     * before it.
     *
     * @return array<int, array{name: string, elapsed: float, delta: float}>
     */
    public static function marks(): array
    {
        $baseline = self::baseline();
        $previous = $baseline;
        $out = [];

        foreach (self::$marks as $mark) {
            $out[] = [
                'name'    => $mark['name'],
                'elapsed' => ($mark['at'] - $baseline) * 1000,
                'delta'   => ($mark['at'] - $previous) * 1000,
            ];

            $previous = $mark['at'];
        }

        return $out;
    }

    /**
     * One line naming each stage and what it cost, in order.
     *
     * Reads as "stage=delta" with the running total last, so a tail of the log
     * shows which stage moved between two runs rather than only that the total
     * did.
     */
    public static function line(string $method = '', string $path = ''): string
    {
        $marks = self::marks();

        if ($marks === []) {
            return '';
        }

        $parts = [];

        foreach ($marks as $mark) {
            $parts[] = sprintf('%s=%.2f', $mark['name'], $mark['delta']);
        }

        $total = end($marks)['elapsed'];

        foreach (self::$counts as $name => $times) {
            $parts[] = sprintf('%s#%d', $name, $times);
        }

        return sprintf(
            '%s %s %s total=%.2fms',
            date('H:i:s'),
            trim($method . ' ' . $path) ?: '-',
            implode(' ', $parts),
            $total
        );
    }

    /**
     * Append the profile for this request to a log file.
     *
     * Appended with LOCK_EX because php-fpm serves requests in parallel
     * processes, and a profile that interleaves two requests is worse than no
     * profile.
     */
    public static function write(string $file, string $method = '', string $path = ''): void
    {
        if (! self::enabled()) {
            return;
        }

        $line = self::line($method, $path);

        if ($line === '') {
            return;
        }

        $directory = dirname($file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Forget every mark, for a process that serves more than one request. */
    public static function reset(): void
    {
        self::$marks = [];
        self::$counts = [];
        self::$baseline = null;
    }
}
