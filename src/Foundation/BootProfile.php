<?php

namespace Nitro\Foundation;

use Nitro\Debug\Timeline;

/**
 * Time the stages of one request, from when PHP received it.
 *
 * Off unless NITRO_PROFILE is set in the environment. Each request appends one line to the profile log.
 */
final class BootProfile
{
    /** @var array<int, array{name: string, at: float}> */
    private static array $marks = [];

    /** @var array<string, int> */
    private static array $counts = [];

    private static ?bool $enabled = null;

    private static ?float $baseline = null;

    /**
     * Determine whether profiling is on, read from the environment since it starts before configuration loads.
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

    /**
     * Record the moment a named stage finished, and pass it to the request timeline.
     *
     * @param ?string $detail The particulars, for a timeline that shows them.
     */
    public static function mark(string $name, ?string $detail = null): void
    {
        Timeline::mark($name, $detail);

        if (! self::enabled()) {
            return;
        }

        self::$marks[] = ['name' => $name, 'at' => microtime(true)];
    }

    /** Count something that happens many times a request, such as container resolutions. */
    public static function count(string $name, int $times = 1): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$counts[$name] = (self::$counts[$name] ?? 0) + $times;
    }

    /** Get when the request began, as PHP recorded it. */
    public static function baseline(): float
    {
        if (self::$baseline === null) {
            $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

            self::$baseline = is_numeric($start) ? (float) $start : microtime(true);
        }

        return self::$baseline;
    }

    /**
     * Get the marks, each with its elapsed time and the time since the mark before it, in milliseconds.
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

    /** Format the profile as one line: each stage's time, the counts, then the total. */
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

    /** Append this request's profile line to a log file, locked against parallel requests. */
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
}
