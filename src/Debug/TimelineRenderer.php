<?php

namespace Nitro\Debug;

/**
 * Shows a recorded request as a list of steps in the order they ran.
 */
final class TimelineRenderer
{
    /** @return array{total: float, peakMb: float, steps: array<int, array<string, mixed>>} */
    public function toArray(): array
    {
        $steps = Timeline::steps();

        return [
            'total' => $steps === [] ? 0.0 : end($steps)['ms'],
            'peakMb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'steps' => $steps,
        ];
    }

    public function toText(): string
    {
        $data = $this->toArray();

        $lines = [sprintf('Request timeline — %.2fms, %.1fMB peak', $data['total'], $data['peakMb'])];
        $lines[] = str_repeat('-', 72);

        foreach ($data['steps'] as $step) {
            $lines[] = sprintf(
                '%9.2fms  %+7.2f  %s%s',
                $step['ms'],
                $step['sinceMs'],
                $step['step'],
                $step['detail'] === null ? '' : '  ' . $step['detail'],
            );
        }

        return implode("\n", $lines) . "\n";
    }

    public function toHtml(): string
    {
        $data = $this->toArray();

        $rows = '';

        foreach ($data['steps'] as $step) {
            $rows .= $this->row($step, $data['total']);
        }

        $total = number_format($data['total'], 2);

        return <<<HTML
        <div id="nitro-timeline" style="
            position:fixed; inset:auto 0 0 0; z-index:2147483647; max-height:45vh; overflow:auto;
            background:#0f1117; color:#e2e4eb; border-top:2px solid #ff6b35;
            font:12px/1.5 ui-monospace,Menlo,Consolas,monospace; padding:12px 16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <b style="color:#ff6b35">Request timeline</b>
                <span style="color:#8b8fa3">{$total}ms &middot; {$data['peakMb']}MB peak &middot; {$this->count($data)} steps</span>
            </div>
            <table style="width:100%;border-collapse:collapse">{$rows}</table>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function count(array $data): int
    {
        return count($data['steps']);
    }

    /**
     * One row, with a bar showing where in the request it happened.
     *
     * @param array<string, mixed> $step
     */
    private function row(array $step, float $total): string
    {
        $at = $total > 0 ? ($step['ms'] / $total) * 100 : 0;
        $width = $total > 0 ? max(0.4, ($step['sinceMs'] / $total) * 100) : 0.4;

        $name = $this->escape((string) $step['step']);
        $detail = $step['detail'] === null
            ? ''
            : '<span style="color:#6b7280"> ' . $this->escape((string) $step['detail']) . '</span>';

        // A step that took longer than a tenth of the request is worth the eye.
        $colour = $step['sinceMs'] > $total / 10 ? '#ffb38a' : '#7fd1ff';

        return <<<HTML
        <tr>
            <td style="color:#8b8fa3;text-align:right;white-space:nowrap;padding-right:10px">{$step['ms']}ms</td>
            <td style="color:#6b7280;text-align:right;white-space:nowrap;padding-right:10px">+{$step['sinceMs']}</td>
            <td style="width:22%;padding-right:10px">
                <div style="position:relative;height:6px;background:#1a1d27;border-radius:3px">
                    <div style="position:absolute;left:{$at}%;width:{$width}%;top:0;height:6px;background:{$colour};border-radius:3px"></div>
                </div>
            </td>
            <td style="color:{$colour}">{$name}{$detail}</td>
            <td style="color:#6b7280;text-align:right;white-space:nowrap">{$step['memoryMb']}MB</td>
        </tr>
        HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
