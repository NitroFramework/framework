<?php

namespace Nitro\Console\View;

use Nitro\Console\Support\Style;

/**
 * Renders the modern Laravel-style console UI — the INFO/SUCCESS/WARN/ERROR
 * badges, dotted task and two-column lines, bullet lists, alerts, and the
 * interactive prompts — using plain ANSI (no Symfony, no termwind). Exposed on
 * a command as $this->components.
 */
class Components
{
    public function __construct(protected Style $style) {}

    // ─── Badges (Laravel's line.php: "  LABEL  message") ────────────────────

    public function info(string $message): void
    {
        $this->badge('INFO', 'blue', 'white', $message);
    }

    public function success(string $message): void
    {
        $this->badge('SUCCESS', 'green', 'white', $message);
    }

    public function warn(string $message): void
    {
        $this->badge('WARN', 'yellow', 'black', $message);
    }

    public function error(string $message): void
    {
        $this->badge('ERROR', 'red', 'white', $message);
    }

    protected function badge(string $title, string $bg, string $fg, string $message): void
    {
        $label = $this->style->apply(' ' . $title . ' ', $fg, $bg, true);
        $this->writeln('');
        $this->writeln('  ' . $label . '  ' . $this->style->format($message));
    }

    // ─── Lines / lists ──────────────────────────────────────────────────────

    public function line(string $message): void
    {
        $this->writeln('  ' . $this->style->format($message));
    }

    public function bulletList(array $items): void
    {
        foreach ($items as $item) {
            $this->writeln('  ' . $this->style->apply('⇂', 'gray') . ' ' . $this->style->format((string) $item));
        }
    }

    public function twoColumnDetail(string $first, string $second = ''): void
    {
        $width = min($this->terminalWidth(), 150);
        $dots = max($width - Style::width($first) - Style::width($second) - 6, 0);
        $fill = $this->style->apply(str_repeat('.', $dots), 'gray');

        $line = '  ' . $this->style->format($first) . ' ' . $fill;
        if ($second !== '') {
            $line .= ' ' . $this->style->format($second);
        }

        $this->writeln($line);
    }

    /**
     * Run a task, printing "  description ......... DONE|FAIL". The callable's
     * return value decides the outcome (false/throw = FAIL).
     */
    public function task(string $description, ?callable $task = null): bool
    {
        $this->write('  ' . $this->style->format($description) . ' ');

        $ok = true;
        try {
            $ok = ($task ? $task() : true) !== false;
        } catch (\Throwable $e) {
            $ok = false;
        }

        $status = $ok
            ? $this->style->apply('DONE', 'green', null, true)
            : $this->style->apply('FAIL', 'red', null, true);

        $width = min($this->terminalWidth(), 150);
        $dots = max($width - Style::width($description) - 10, 0);
        $this->write($this->style->apply(str_repeat('.', $dots), 'gray'));
        $this->writeln(' ' . $status);

        return $ok;
    }

    public function alert(string $message): void
    {
        $padded = '     ' . $message . '     ';
        $bar = str_repeat(' ', Style::width($padded));

        $this->writeln('');
        $this->writeln('  ' . $this->style->apply($bar, null, 'yellow'));
        $this->writeln('  ' . $this->style->apply($padded, 'black', 'yellow', true));
        $this->writeln('  ' . $this->style->apply($bar, null, 'yellow'));
        $this->writeln('');
    }

    // ─── Interaction ────────────────────────────────────────────────────────

    public function ask(string $question, ?string $default = null): string
    {
        $this->write('  ' . $this->style->apply($question, 'green') . ($default !== null ? " [{$default}]" : '') . ': ');
        $answer = rtrim((string) fgets(STDIN), "\r\n");

        return $answer === '' && $default !== null ? $default : $answer;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->write('  ' . $this->style->apply($question, 'green') . ($default ? ' [Y/n]' : ' [y/N]') . ': ');
        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes', '1', 'true'], true);
    }

    public function secret(string $question): string
    {
        $this->write('  ' . $this->style->apply($question, 'green') . ': ');

        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
            @shell_exec('stty -echo 2>/dev/null');
            $answer = rtrim((string) fgets(STDIN), "\r\n");
            @shell_exec('stty echo 2>/dev/null');
            $this->writeln('');
            return $answer;
        }

        return rtrim((string) fgets(STDIN), "\r\n");
    }

    public function choice(string $question, array $choices, int|string|null $default = null): string
    {
        $this->writeln('  ' . $this->style->apply($question, 'green'));
        foreach ($choices as $key => $choice) {
            $this->writeln('    ' . $this->style->apply("[{$key}]", 'gray') . ' ' . $choice);
        }
        $this->write('  > ');

        $answer = trim((string) fgets(STDIN));
        if ($answer === '' && $default !== null) {
            return (string) ($choices[$default] ?? $default);
        }

        return (string) ($choices[$answer] ?? (in_array($answer, $choices, true) ? $answer : ($choices[$default] ?? '')));
    }

    // ─── Output plumbing ────────────────────────────────────────────────────

    protected function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    protected function writeln(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    protected function terminalWidth(): int
    {
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns)) {
            return (int) $columns;
        }

        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
            $size = @shell_exec('stty size 2>/dev/null');
            if ($size && preg_match('/\d+\s+(\d+)/', $size, $m)) {
                return (int) $m[1];
            }
        }

        return 80;
    }
}
