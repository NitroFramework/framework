<?php

namespace Nitro\Debug;

/**
 * Shows a captured stack as the sequence of calls that led to it.
 *
 * Read top to bottom: the entry point first, each step the file and
 * method it called, down to where the trace was asked for. That is the
 * opposite of how PHP numbers a trace, and the order the path actually
 * happened in.
 */
final class TraceRenderer
{
    /** @param array{file: ?string, line: ?int}|null $origin Where the trace was taken. */
    public function __construct(
        private Backtrace $trace,
        private ?string $label = null,
        private ?array $origin = null,
    ) {}

    /**
     * The frames as data, in call order.
     *
     * @return array{label: ?string, taken_at: ?string, depth: int, frames: array<int, array<string, mixed>>}
     */
    public function toArray(bool $newestFirst = false): array
    {
        $frames = $this->trace->frames();

        if (! $newestFirst) {
            $frames = array_reverse($frames);
        }

        $step = 1;

        foreach ($frames as $index => $frame) {
            $frames[$index]['step'] = $step++;
        }

        return [
            'label' => $this->label,
            'taken_at' => $this->takenAt(),
            'depth' => $this->trace->depth(),
            'frames' => array_values($frames),
        ];
    }

    private function takenAt(): ?string
    {
        if ($this->origin === null || $this->origin['file'] === null) {
            return null;
        }

        return $this->shorten($this->origin['file']) . ':' . ($this->origin['line'] ?? '?');
    }

    public function toText(bool $newestFirst = false): string
    {
        $data = $this->toArray($newestFirst);

        $lines = [$data['label'] === null ? 'Stack trace' : 'Stack trace — ' . $data['label']];

        if ($data['taken_at'] !== null) {
            $lines[] = 'taken at ' . $data['taken_at'];
        }

        $lines[] = str_repeat('-', 60);

        foreach ($data['frames'] as $frame) {
            $lines[] = sprintf('%2d. %s', $frame['step'], $frame['call']);
            $lines[] = sprintf('    %s:%s', $frame['file'] ?? '[internal]', $frame['line'] ?? '?');

            if ($frame['args'] !== '') {
                $lines[] = '    (' . $frame['args'] . ')';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function toHtml(bool $newestFirst = false): string
    {
        $data = $this->toArray($newestFirst);

        $rows = '';

        foreach ($data['frames'] as $frame) {
            $rows .= $this->row($frame);
        }

        $heading = $data['label'] === null
            ? 'Stack trace'
            : 'Stack trace — ' . $this->escape($data['label']);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$heading}</title>
        <style>
            :root { color-scheme: dark; }
            body {
                margin: 0; padding: 24px;
                background: #0f1117; color: #e2e4eb;
                font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            }
            h1 { font-size: 18px; margin: 0 0 4px; }
            .meta { color: #8b8fa3; font-size: 13px; margin-bottom: 20px; }
            ol { list-style: none; margin: 0; padding: 0; counter-reset: step; }
            li {
                border-left: 2px solid #2a2e3d; padding: 10px 0 10px 16px;
                margin-left: 8px; position: relative;
            }
            li::before {
                content: counter(step); counter-increment: step;
                position: absolute; left: -13px; top: 12px;
                width: 24px; height: 24px; border-radius: 50%;
                background: #2a2e3d; color: #8b8fa3;
                font-size: 11px; line-height: 24px; text-align: center;
            }
            .call { color: #7fd1ff; font-family: ui-monospace, Menlo, Consolas, monospace; }
            .where { color: #8b8fa3; font-size: 12px; font-family: ui-monospace, Menlo, Consolas, monospace; }
            .where b { color: #b9bdc9; font-weight: 600; }
            .args { color: #6b7280; font-size: 12px; font-family: ui-monospace, Menlo, Consolas, monospace; }
            li:last-child { border-left-color: #ff6b35; }
            li:last-child .call { color: #ffb38a; }
        </style>
        </head>
        <body>
        <h1>{$heading}</h1>
        <div class="meta">{$this->metaLine($data)}</div>
        <ol>{$rows}</ol>
        </body>
        </html>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function metaLine(array $data): string
    {
        $meta = $data['depth'] . ' frames, in the order they were called';

        return $data['taken_at'] === null
            ? $meta
            : $meta . ' &middot; taken at ' . $this->escape((string) $data['taken_at']);
    }

    /** @param array<string, mixed> $frame */
    private function row(array $frame): string
    {
        $call = $this->escape((string) $frame['call']);
        $file = $this->escape((string) ($frame['file'] ?? '[internal]'));
        $line = $frame['line'] ?? '?';

        $args = $frame['args'] === ''
            ? ''
            : '<div class="args">(' . $this->escape((string) $frame['args']) . ')</div>';

        return <<<HTML
        <li>
            <div class="call">{$call}</div>
            <div class="where">{$this->shorten($file)}<b>:{$line}</b></div>
            {$args}
        </li>
        HTML;
    }

    /**
     * Drop the part of the path every frame shares.
     *
     * A column of identical absolute paths hides the one thing that
     * differs between frames, which is the file.
     */
    private function shorten(string $path): string
    {
        $root = $this->projectRoot();

        return $root !== null && str_starts_with($path, $root)
            ? substr($path, strlen($root) + 1)
            : $path;
    }

    private function projectRoot(): ?string
    {
        try {
            return function_exists('base_path') ? rtrim(base_path(), '/\\') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
