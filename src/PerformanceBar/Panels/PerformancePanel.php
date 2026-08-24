<?php

namespace Nitro\PerformanceBar\Panels;

use Nitro\PerformanceBar\Contracts\PanelInterface;

/**
 * Performance-bar panel: request timing and memory metrics.
 */
class PerformancePanel implements PanelInterface
{
    private static float $startTime;
    private static int $startMemory;
    private static array $startClasses = [];
    private static array $startFiles = [];
    private static array $timers = [];
    private static bool $started = false;

    private array $metrics = [];

    public static function start(): void
    {
        self::$startTime   = microtime(true);
        self::$startMemory = memory_get_usage();
        self::$startClasses = get_declared_classes();
        self::$startFiles   = get_included_files();
        self::$timers['app_start'] = self::$startTime;
        self::$started = true;
    }

    public static function mark(string $name): float
    {
        if (!self::$started) return 0.0;
        $time = microtime(true);
        self::$timers[$name] = $time;
        return $time;
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    public function getId(): string
    {
        return 'performance';
    }
    public function getName(): string
    {
        return '⏱ Performance';
    }

    public function collect(): void
    {
        if (!self::$started) return;

        $endTime   = microtime(true);
        $endMemory = memory_get_usage();
        $endClasses = get_declared_classes();
        $endFiles   = get_included_files();

        $newClasses = array_diff($endClasses, self::$startClasses);
        $newFiles   = array_diff($endFiles, self::$startFiles);

        $frameworkClasses = array_filter(
            $newClasses,
            fn($class) =>
            str_starts_with($class, 'Nitro\\') || str_starts_with($class, 'App\\')
        );
        $frameworkFiles = array_filter(
            $newFiles,
            fn($file) =>
            str_contains($file, 'src') || str_contains($file, 'app')
        );

        $isWorker = function_exists('frankenphp_handle_request');

        $this->metrics = [
            'execution_time' => ($endTime - self::$startTime) * 1000,
            'memory_used'    => ($endMemory - self::$startMemory) / 1024 / 1024,
            'peak_memory'    => memory_get_peak_usage() / 1024 / 1024,
            'classes_loaded' => count($frameworkClasses),
            'files_loaded'   => count($frameworkFiles),
            'total_files'    => count($newFiles),
            'timers'         => self::$timers,
            'mode'           => $isWorker ? '⚡ Worker' : '🐘 Standard',
            'loaded_files'   => array_values($newFiles),
        ];
    }

    public function renderBadge(): string
    {
        if (empty($this->metrics)) return '';
        return $this->formatTime($this->metrics['execution_time']);
    }

    public function renderContent(): string
    {
        if (empty($this->metrics)) return '<p style="color:#555;padding:20px">Not collected.</p>';

        return $this->renderMetricCards()
            . $this->renderTimersAndFiles();
    }

    private function renderMetricCards(): string
    {
        $metrics = $this->metrics;
        $timeFormatted = $this->formatTime($metrics['execution_time']);
        $memFormatted  = $this->formatMemory($metrics['memory_used']);
        $peakFormatted = $this->formatMemory($metrics['peak_memory']);

        return <<<HTML
<div class="ndb-perf-grid">
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$timeFormatted}</div>
        <div class="ndb-metric-lbl">Execution Time</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$memFormatted}</div>
        <div class="ndb-metric-lbl">Memory Used</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$peakFormatted}</div>
        <div class="ndb-metric-lbl">Peak Memory</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$metrics['classes_loaded']}</div>
        <div class="ndb-metric-lbl">Classes Loaded</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$metrics['files_loaded']}</div>
        <div class="ndb-metric-lbl">Framework Files</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$metrics['mode']}</div>
        <div class="ndb-metric-lbl">Mode</div>
    </div>
</div>
HTML;
    }

    private function renderTimersAndFiles(): string
    {
        $metrics       = $this->metrics;
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        $filesHtml = '';
        foreach ($metrics['loaded_files'] as $file) {
            $short = str_replace($docRoot, '', $file);
            $filesHtml .= "<div class='ndb-file-row'>{$short}</div>";
        }

        $timersHtml = '';
        $prev = null;
        foreach ($metrics['timers'] as $name => $time) {
            if ($prev !== null) {
                $diff = round(($time - $prev) * 1000, 3);
                $timersHtml .= "<tr><td class='ndb-td'>{$name}</td><td class='ndb-td ndb-num'>{$diff}ms</td></tr>";
            }
            $prev = $time;
        }

        $totalFiles = $metrics['total_files'];

        return <<<HTML
<div class="ndb-two-col">
    <div>
        <div class="ndb-section-title">Timers</div>
        <table class="ndb-table">
            <thead><tr><th class="ndb-th">Checkpoint</th><th class="ndb-th">Δ Time</th></tr></thead>
            <tbody>{$timersHtml}</tbody>
        </table>
    </div>
    <div>
        <div class="ndb-section-title">Loaded Files ({$totalFiles})</div>
        <div class="ndb-file-list">{$filesHtml}</div>
    </div>
</div>
HTML;
    }
    private function formatTime(float $milliseconds): string
    {
        return $milliseconds < 1 ? round($milliseconds * 1000) . 'μs' : round($milliseconds, 2) . 'ms';
    }

    private function formatMemory(float $megabytes): string
    {
        return $megabytes < 1 ? round($megabytes * 1024, 2) . 'KB' : round($megabytes, 2) . 'MB';
    }
}
