<?php

namespace Nitro\Debug;

/**
 * The call stack at the point it was asked for, as data.
 *
 * Separate from how it is shown, so the same frames can become a page,
 * a JSON body or a log line.
 */
final class Backtrace
{
    /** @param array<int, array<string, mixed>> $frames */
    private function __construct(private array $frames) {}

    /**
     * Capture the stack, leaving out the frames that did the capturing.
     *
     * @param int $skip Further frames to drop from the top.
     */
    public static function capture(int $skip = 0): self
    {
        $raw = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

        return new self(array_values(array_slice($raw, $skip + 1)));
    }

    /** Build one from an exception that has already been thrown. */
    public static function fromThrowable(\Throwable $exception): self
    {
        return new self(array_merge(
            [['file' => $exception->getFile(), 'line' => $exception->getLine()]],
            $exception->getTrace(),
        ));
    }

    /**
     * Where the top frame was called from.
     *
     * For a trace taken by a helper this is the line the helper was put
     * on, which is the one thing the frames below it do not say.
     *
     * @return array{file: ?string, line: ?int}
     */
    public function origin(): array
    {
        $top = $this->frames[0] ?? [];

        return [
            'file' => isset($top['file']) ? (string) $top['file'] : null,
            'line' => isset($top['line']) ? (int) $top['line'] : null,
        ];
    }

    /**
     * The same stack without its top frame.
     *
     * Drops the helper that took the trace, so what is left is the path
     * through the application rather than the act of looking at it.
     */
    public function withoutTop(): self
    {
        return new self(array_slice($this->frames, 1));
    }

    /**
     * One entry per frame: what was called, and from where.
     *
     * The file and line are the call site, not the body — the same way
     * a thrown exception reports its trace, so the two read alike.
     *
     * @return array<int, array{index: int, call: string, file: ?string, line: ?int, args: string}>
     */
    public function frames(): array
    {
        $frames = [];

        foreach ($this->frames as $index => $frame) {
            $frames[] = [
                'index' => $index,
                'call' => $this->callFor($frame),
                'file' => isset($frame['file']) ? (string) $frame['file'] : null,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'args' => $this->argumentsFor($frame),
            ];
        }

        return $frames;
    }

    /** How deep the stack was. */
    public function depth(): int
    {
        return count($this->frames);
    }

    /** @param array<string, mixed> $frame */
    private function callFor(array $frame): string
    {
        $function = (string) ($frame['function'] ?? '{closure}');

        if (! isset($frame['class'])) {
            return $function . '()';
        }

        return $frame['class'] . ($frame['type'] ?? '::') . $function . '()';
    }

    /**
     * A one-line summary of the arguments.
     *
     * Summarised rather than dumped: an argument is often a request, a
     * container or a model graph, and printing one would bury the frame
     * it belongs to.
     *
     * @param array<string, mixed> $frame
     */
    private function argumentsFor(array $frame): string
    {
        $arguments = $frame['args'] ?? null;

        if (! is_array($arguments) || $arguments === []) {
            return '';
        }

        return implode(', ', array_map($this->describe(...), $arguments));
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_object($value) => $value::class,
            is_array($value) => 'Array(' . count($value) . ')',
            is_string($value) => '"' . (mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '…' : $value) . '"',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }

    /** The frames as plain text, one per line. */
    public function toText(): string
    {
        $lines = [];

        foreach ($this->frames() as $frame) {
            $lines[] = sprintf(
                '#%d %s  %s:%s  (%s)',
                $frame['index'],
                $frame['call'],
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '?',
                $frame['args'],
            );
        }

        return implode("\n", $lines);
    }
}
