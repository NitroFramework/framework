<?php

namespace Nitro\Debug;

/**
 * Every step of a request, recorded as it happens.
 *
 * A stack trace can only show the path into the line it was taken on —
 * the response has not been sent yet, terminate() has not run, and
 * neither is on the stack to be read. This records instead: each step
 * writes an entry as it passes, and the whole ordered list is printed
 * at shutdown, which is genuinely the last thing to run.
 *
 * Off unless asked for, and when off {@see mark()} is a bool test and a
 * return — a recorder that cost anything would change what it records.
 */
final class Timeline
{
    /** @var array<int, array{step: string, at: float, memory: int, detail: ?string}> */
    private static array $steps = [];

    private static ?bool $enabled = null;

    private static ?float $baseline = null;

    /** Whether anything has already arranged to print the timeline. */
    private static bool $printing = false;

    /**
     * Whether recording is on.
     *
     * Turned on by NITRO_TIMELINE in the environment, or by ?timeline
     * on the query string — the second so a page can be looked at
     * without restarting anything.
     */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $fromEnv = getenv('NITRO_TIMELINE');

        return self::$enabled = ($fromEnv !== false && $fromEnv !== '' && $fromEnv !== '0')
            || isset($_GET['timeline']);
    }

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Record a step.
     *
     * @param string  $step   What happened, in the order it happened.
     * @param ?string $detail The particulars — a route, a query, a view.
     */
    public static function mark(string $step, ?string $detail = null): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$steps[] = [
            'step' => $step,
            'at' => microtime(true),
            'memory' => memory_get_usage(true),
            'detail' => $detail,
        ];
    }

    /**
     * Time a callback and record how long it took.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function measure(string $step, callable $work, ?string $detail = null): mixed
    {
        if (! self::enabled()) {
            return $work();
        }

        self::mark($step . ' →', $detail);

        try {
            return $work();
        } finally {
            self::mark($step . ' ←', $detail);
        }
    }

    /**
     * When the request began.
     *
     * PHP sets this before any application code runs, so autoloading is
     * inside the first measurement rather than invisible ahead of it.
     */
    public static function baseline(): float
    {
        return self::$baseline ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    /**
     * The steps, each with how long after the request it happened.
     *
     * @return array<int, array{step: string, ms: float, sinceMs: float, memoryMb: float, detail: ?string}>
     */
    public static function steps(): array
    {
        $baseline = self::baseline();
        $previous = $baseline;
        $steps = [];

        foreach (self::$steps as $entry) {
            $steps[] = [
                'step' => $entry['step'],
                'ms' => round(($entry['at'] - $baseline) * 1000, 2),
                'sinceMs' => round(($entry['at'] - $previous) * 1000, 2),
                'memoryMb' => round($entry['memory'] / 1024 / 1024, 1),
                'detail' => $entry['detail'],
            ];

            $previous = $entry['at'];
        }

        return $steps;
    }

    public static function reset(): void
    {
        self::$steps = [];
        self::$baseline = null;
        self::$printing = false;
    }

    /**
     * Arrange for the timeline to be printed when the process ends.
     *
     * Registered rather than called, because the point is to record the
     * steps that happen after everything else has finished — the
     * response going out, terminate(), and the shutdown itself.
     */
    public static function printAtShutdown(): void
    {
        if (! self::enabled() || self::$printing) {
            return;
        }

        self::$printing = true;

        register_shutdown_function(static function (): void {
            self::mark('shutdown');

            echo (new TimelineRenderer())->toHtml();
        });
    }
}
