<?php

namespace Nitro\PerformanceBar;


/**
 * Performance Metrics Tracker for NitroPHP
 * 
 * Provides comprehensive timing and memory usage tracking throughout the application lifecycle.
 * Features include:
 * - Visual performance bar display
 * - Timing checkpoints
 * - Memory usage tracking
 * - Class and file loading metrics
 * - HTMX/AJAX request support
 * 
 * @package Nitro\PerformanceBar
 */
class PerformanceMetrics
{
    /**
     * Application start time (microtime)
     */
    private static float $startTime;

    /**
     * Initial memory usage
     */
    private static int $startMemory;

    /**
     * Classes loaded at start
     */
    private static array $startClasses;

    /**
     * Files loaded at start
     */
    private static array $startFiles;

    /**
     * Collection of timing checkpoints
     */
    private static array $timers = [];

    /**
     * Performance tracking enabled flag
     */
    private static bool $enabled = false;

    /**
     * Start performance tracking.
     *
     * `start()` runs at t=0 in Application::create(), BEFORE the environment
     * file and config are loaded. We therefore cannot ask config('app.debug')
     * here — and reading the raw APP_DEBUG env is unreliable, because on a
     * standard SAPI the .env file hasn't been parsed yet, so a value that lives
     * only in .env is invisible at this point. That mismatch is why metrics used
     * to silently stay off in debug. So:
     *
     *   - We ALWAYS capture the full baseline (timing, memory, class + file
     *     snapshots) at t=0. This keeps `@elapsed_time` / `@memory_usage` and the
     *     class/file counts accurate regardless of when `enabled` is decided.
     *   - The authoritative enabled decision is DEFERRED to setEnabled(), which
     *     Application calls once config is loaded, using config('app.debug') so
     *     the gate matches every other debug feature in the framework.
     *
     * The provisional env/query gate below only affects the brief window before
     * setEnabled() runs (and worker mode, where the runtime exposes a real
     * APP_DEBUG env). The owner can force a value via the $enabled argument.
     */
    public static function start(?bool $enabled = null): void
    {
        self::$startTime   = microtime(true);
        self::$startMemory = memory_get_usage();
        self::$timers['app_start'] = self::$startTime;

        // Baseline snapshots — always captured at t=0 so counts stay accurate no
        // matter when the enabled decision is finalized.
        self::$startClasses = get_declared_classes();
        self::$startFiles   = get_included_files();

        if ($enabled !== null) {
            self::$enabled = $enabled;
            return;
        }

        // Provisional gate for the pre-config window / worker mode (where a real
        // APP_DEBUG env is present). setEnabled() applies the config-based value.
        self::$enabled = self::debugFromEnv() || isset($_GET['performance']);
    }

    /**
     * Apply the authoritative enabled decision once config is available.
     * Called by the Application after bootstrap so the gate derives from
     * config('app.debug') (plus the ?performance override) — consistent with
     * the rest of the framework and immune to how APP_DEBUG is delivered.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /** Read APP_DEBUG straight from the process environment (best-effort). */
    private static function debugFromEnv(): bool
    {
        $debug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');

        return is_string($debug) ? filter_var($debug, FILTER_VALIDATE_BOOLEAN) : (bool) $debug;
    }

    /**
     * Is performance tracking currently enabled?
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Mark a timing checkpoint
     * 
     * @param string $name Checkpoint name
     * @return float The current microtime
     */
    public static function mark(string $name): float
    {
        if (!self::$enabled) {
            return 0.0;
        }

        $time = microtime(true);
        self::$timers[$name] = $time;
        return $time;
    }

    /**
     * Get elapsed time since application start
     * 
     * @return float Elapsed time in seconds
     */
    public static function getElapsedTime(): float
    {
        if (!isset(self::$startTime)) {
            return 0.0;
        }

        return microtime(true) - self::$startTime;
    }

    /**
     * Get elapsed time between two checkpoints
     * 
     * @param string $start Start checkpoint name
     * @param string $end End checkpoint name (optional, uses current time if not provided)
     * @return float Elapsed time in seconds
     */
    public static function getElapsedBetween(string $start, ?string $end = null): float
    {
        if (!isset(self::$timers[$start])) {
            return 0.0;
        }

        $endTime = $end && isset(self::$timers[$end])
            ? self::$timers[$end]
            : microtime(true);

        return $endTime - self::$timers[$start];
    }

    /**
     * Get peak memory usage in MB
     * 
     * @return float Peak memory usage in megabytes
     */
    public static function getMemoryUsage(): float
    {
        return memory_get_peak_usage() / 1024 / 1024;
    }

    /**
     * Get current memory usage in MB
     * 
     * @return float Current memory usage in megabytes
     */
    public static function getCurrentMemoryUsage(): float
    {
        return memory_get_usage() / 1024 / 1024;
    }

    /**
     * Get current metrics (for programmatic access)
     * 
     * @return array Performance metrics
     */
    public static function getMetrics(): array
    {
        if (!self::$enabled) {
            return [];
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $endClasses = get_declared_classes();
        $endFiles = get_included_files();

        $newClasses = array_diff($endClasses, self::$startClasses);
        $newFiles = array_diff($endFiles, self::$startFiles);

        $frameworkClasses = array_filter($newClasses, function ($class) {
            return strpos($class, 'Nitro\\') === 0 || strpos($class, 'App\\') === 0;
        });

        $frameworkFiles = array_filter($newFiles, function ($file) {
            return strpos($file, 'src') !== false || strpos($file, 'app') !== false;
        });

        return [
            'execution_time' => ($endTime - self::$startTime) * 1000, // in milliseconds
            'memory_used' => ($endMemory - self::$startMemory) / 1024 / 1024, // in MB
            'peak_memory' => memory_get_peak_usage() / 1024 / 1024, // in MB
            'current_memory' => $endMemory / 1024 / 1024, // in MB
            'classes_loaded' => count($frameworkClasses),
            'files_loaded' => count($frameworkFiles),
            'total_files' => count($newFiles),
            'timers' => self::$timers,
        ];
    }

    /**
     * Get all performance stats as an array (alias for getMetrics with additional info)
     * 
     * @return array Performance statistics
     */
    public static function getStats(): array
    {
        if (!self::$enabled) {
            return [];
        }

        return [
            'elapsed_time' => self::getElapsedTime(),
            'memory_usage' => self::getMemoryUsage(),
            'current_memory' => self::getCurrentMemoryUsage(),
            'start_time' => self::$startTime ?? 0,
            'timers' => self::$timers,
        ];
    }

    /**
     * Generate and display performance report
     */
    public static function report(): void
    {
        if (!self::$enabled) {
            return;
        }

        $metrics = self::getMetrics();

        // Display format (check if it's an HTMX request)
        if (self::isHtmxRequest()) {
            self::outputJson($metrics);
        }
    }

    /**
     * Output metrics as JSON (for AJAX requests)
     * 
     * @param array $metrics Performance metrics
     */
    private static function outputJson(array $metrics): void
    {
        header('X-Performance-Time: ' . round($metrics['execution_time'], 2) . 'ms');
        header('X-Performance-Memory: ' . round($metrics['memory_used'], 2) . 'MB');
        header('X-Performance-Classes: ' . $metrics['classes_loaded']);
        header('X-Performance-Files: ' . $metrics['files_loaded']);
    }

    /**
     * Get performance bar HTML for injection
     * 
     * @return string HTML for performance bar
     */
    public static function getPerformanceBarHtml(): string
    {
        if (!self::$enabled) {
            return '';
        }

        $metrics = self::getMetrics();

        // Check if HTMX request
        $isHtmx = self::isHtmxRequest();
        $oobAttribute = $isHtmx ? ' hx-swap-oob="true"' : '';

        $timeFormatted = self::formatTime($metrics['execution_time']);
        $memoryFormatted = self::formatMemory($metrics['memory_used']);
        $peakFormatted = self::formatMemory($metrics['peak_memory']);
        $classCount = self::formatNumber($metrics['classes_loaded']);
        $fileCount = self::formatNumber($metrics['files_loaded']);
        $totalFiles = self::formatNumber($metrics['total_files']);

        // Build files list
        $filesHtml = '';
        foreach (array_diff(get_included_files(), self::$startFiles) as $file) {
            $short = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);
            $filesHtml .= "<div style='padding:3px 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:12px;'>{$short}</div>";
        }

        $isWorker = function_exists('frankenphp_handle_request');
        $mode = $isWorker ? '⚡ Worker' : '🐘 Standard';

        return <<<HTML

        
<style>
    .nitro-performance-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 20px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 13px;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
        z-index: 999999;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .nitro-metric {
        display: inline-flex;
        align-items: center;
        margin-right: 25px;
        padding: 6px 12px;
        background: rgba(255,255,255,0.1);
        border-radius: 6px;
        backdrop-filter: blur(10px);
    }
    .nitro-metric-label {
        opacity: 0.9;
        margin-right: 8px;
        font-weight: 500;
    }
    .nitro-metric-value {
        font-weight: bold;
        font-size: 14px;
    }
    .nitro-performance-toggle {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 8px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s;
    }
    .nitro-performance-toggle:hover {
        background: rgba(255,255,255,0.3);
    }
</style>

<div id="nitro-performance-bar" class="nitro-performance-bar"{$oobAttribute}>
    <div>
        <span class="nitro-metric">
             <span class="nitro-metric-label">Mode:</span>
            <span class="nitro-metric-value">{$mode}</span>
            <span class="nitro-metric-label">⏱️ Time:</span>
            <span class="nitro-metric-value">{$timeFormatted}</span>
        </span>
        <span class="nitro-metric">
            <span class="nitro-metric-label">💾 Memory:</span>
            <span class="nitro-metric-value">{$memoryFormatted}</span>
        </span>
        <span class="nitro-metric">
            <span class="nitro-metric-label">📊 Peak:</span>
            <span class="nitro-metric-value">{$peakFormatted}</span>
        </span>
        <span class="nitro-metric">
            <span class="nitro-metric-label">🔷 Classes:</span>
            <span class="nitro-metric-value">{$classCount}</span>
        </span>
        <span class="nitro-metric">
            <span class="nitro-metric-label">📁 Files:</span>
            <span class="nitro-metric-value">{$fileCount}</span>
        </span>
    </div>
    <button class="nitro-performance-toggle" onclick="document.getElementById('nitro-perf-details').style.display = document.getElementById('nitro-perf-details').style.display === 'none' ? 'block' : 'none'">
        Details
    </button>
 <div id="nitro-perf-details" style="display:none; position:fixed; bottom:60px; left:0; right:0; background:#1a1a2e; color:white; padding:20px; max-height:400px; overflow-y:auto; border-top:2px solid #667eea;">
    <h4 style="margin:0 0 12px; color:#667eea;">📁 Loaded Files</h4>
    {$filesHtml}
</div>
</div>
HTML;
    }

    /**
     * Replace performance placeholders in output content
     * 
     * @param string $content The content to process
     * @return string Content with replaced placeholders
     */
    public static function replacePlaceholders(string $content): string
    {
        if (!self::$enabled) {
            return $content;
        }

        $replacements = [
            '{elapsed_time}' => number_format(self::getElapsedTime(), 4),
            '{memory_usage}' => number_format(self::getMemoryUsage(), 3),
            '{current_memory}' => number_format(self::getCurrentMemoryUsage(), 3),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Format time for display
     * 
     * @param float $milliseconds Time in milliseconds
     * @return string Formatted time string
     */
    private static function formatTime(float $milliseconds): string
    {
        if ($milliseconds < 1) {
            return round($milliseconds * 1000) . 'μs';
        }
        return round($milliseconds, 2) . 'ms';
    }

    /**
     * Format memory for display
     * 
     * @param float $megabytes Memory in megabytes
     * @return string Formatted memory string
     */
    private static function formatMemory(float $megabytes): string
    {
        if ($megabytes < 1) {
            return round($megabytes * 1024, 2) . 'KB';
        }
        return round($megabytes, 2) . 'MB';
    }

    /**
     * Format number with thousands separator
     * 
     * @param int $number Number to format
     * @return string Formatted number
     */
    private static function formatNumber(int $number): string
    {
        return number_format($number);
    }

    /**
     * Check if the current request was issued by HTMX.
     *
     * @return bool True for an HTMX request
     */
    private static function isHtmxRequest(): bool
    {
        $container = app();
        return $container->has('request') && $container->make('request')->isHtmx();
    }

    /**
     * Get start time
     * 
     * @return float Start time in microtime
     */
    public static function getStartTime(): float
    {
        return self::$startTime ?? microtime(true);
    }

    /**
     * Reset all metrics (useful for testing)
     */
    public static function reset(): void
    {
        self::$startTime = microtime(true);
        self::$startMemory = memory_get_usage();
        self::$startClasses = get_declared_classes();
        self::$startFiles = get_included_files();
        self::$timers = ['app_start' => self::$startTime];

        // In worker mode this runs between requests. Reset the peak so
        // memory_get_peak_usage() reflects the NEXT request's own peak rather
        // than the one-time bootstrap high-water mark, which would otherwise be
        // reported on every request forever (and read like a per-request leak).
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }


    /**
     * Get elapsed time as formatted string in milliseconds
     * 
     * @return string Formatted elapsed time
     */
    public static function elapsedTime(): string
    {
        return number_format(self::getElapsedTime() * 1000, 2);
    }

    /**
     * Get memory usage as formatted string in MB
     * 
     * @return string Formatted memory usage
     */
    public static function memoryUsage(): string
    {
        return number_format(self::getMemoryUsage(), 3);
    }
}
