<?php

namespace Nitro\Console\View;

use Nitro\Console\Support\Style;
use Nitro\Console\Support\Terminal;
use Nitro\Console\Verbosity;

/**
 * Renders the modern Laravel-style console UI — the INFO/SUCCESS/WARN/ERROR
 * badges, dotted task and two-column lines, bullet lists, alerts, and the
 * interactive prompts — using plain ANSI (no Symfony, no termwind). Exposed on
 * a command as $this->components.
 */
class Components
{
    /** How much the caller asked to hear; checked before anything is written. */
    protected Verbosity $verbosity = Verbosity::Normal;

    public function __construct(protected Style $style) {}

    /** Set the level below which output is dropped. */
    public function setVerbosity(Verbosity $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    public function verbosity(): Verbosity
    {
        return $this->verbosity;
    }

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

    protected function badge(string $title, string $background, string $foreground, string $message): void
    {
        $label = $this->style->apply(' ' . $title . ' ', $foreground, $background, true);
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
        } catch (\Throwable $exception) {
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

    /**
     * Ask a question, optionally refusing an answer that does not pass.
     *
     * $validate returns an error message for a bad answer, or null to accept.
     * Without it every answer is accepted, as before.
     *
     *   $this->components->ask('Email', null, fn (string $v): ?string =>
     *       filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'That is not an email address.');
     *
     * The loop gives up rather than spinning forever when there is nothing to
     * read — a closed pipe returns the same empty answer every time, and an
     * unanswerable question repeated is a hung job.
     */
    public function ask(string $question, ?string $default = null, ?callable $validate = null): string
    {
        $attempts = 0;

        while (true) {
            $this->write(
                '  ' . $this->style->apply($question, 'green')
                . ($default !== null ? " [{$default}]" : '') . ': '
            );

            $line = fgets(STDIN);
            $answer = $line === false ? '' : rtrim($line, "\r\n");
            $answer = $answer === '' && $default !== null ? $default : $answer;

            if ($validate === null) {
                return $answer;
            }

            $error = $validate($answer);

            if ($error === null) {
                return $answer;
            }

            if ($line === false || ++$attempts >= 3) {
                $this->error(is_string($error) ? $error : 'Invalid answer.');

                return $answer;
            }

            $this->writeln('  ' . $this->style->apply(is_string($error) ? $error : 'Invalid answer.', 'red'));
        }
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

        $answer = Terminal::readHidden();

        if ($answer !== null) {
            $this->writeln('');

            return $answer;
        }

        /*
         * Nothing here can hide the typing. Said out loud rather than read
         * silently, because a password appearing on screen is something the
         * person typing it needs to know before they type it, not after.
         */
        $this->writeln('');
        $this->writeln('  ' . $this->style->apply('(input will be visible)', 'yellow'));
        $this->write('  > ');

        return rtrim((string) fgets(STDIN), "\r\n");
    }

    /**
     * Choose one option, with the arrow keys where the terminal allows it.
     *
     * Falls back to the numbered prompt wherever keys cannot be read one at a
     * time — Windows, a pipe, a CI job — so the question is always answerable
     * even when it cannot be answered prettily.
     *
     * @param array<array-key, string> $options
     */
    public function select(string $question, array $options, int|string|null $default = null): string
    {
        if ($options === []) {
            return '';
        }

        if (! Terminal::supportsRawMode()) {
            return $this->choice($question, $options, $default);
        }

        $keys = array_keys($options);
        $cursor = array_search($default, $keys, true);
        $cursor = $cursor === false ? 0 : (int) $cursor;

        $chosen = $this->navigate($question, $options, $cursor, false);

        return (string) $options[$keys[$chosen[0]]];
    }

    /**
     * Choose any number of options: space toggles, enter accepts.
     *
     * @param  array<array-key, string> $options
     * @return array<int, string>
     */
    public function multiselect(string $question, array $options, array $default = []): array
    {
        if ($options === []) {
            return [];
        }

        $keys = array_keys($options);

        if (! Terminal::supportsRawMode()) {
            $answer = $this->ask($question . ' (comma-separated)', implode(',', $default));

            return array_values(array_filter(array_map(
                static fn (string $part): string => trim($part),
                explode(',', $answer)
            )));
        }

        $selected = [];

        foreach ($default as $value) {
            $index = array_search($value, $keys, true);

            if ($index !== false) {
                $selected[] = (int) $index;
            }
        }

        $chosen = $this->navigate($question, $options, 0, true, $selected);

        return array_map(
            static fn (int $index): string => (string) $options[$keys[$index]],
            $chosen
        );
    }

    /**
     * Draw the menu and drive it until enter is pressed.
     *
     * The terminal is restored in a finally: a command interrupted mid-menu
     * must not leave the shell without echo, which would make it unusable.
     *
     * @param  array<array-key, string> $options
     * @param  array<int, int>          $selected
     * @return array<int, int>
     */
    protected function navigate(
        string $question,
        array $options,
        int $cursor,
        bool $multiple,
        array $selected = []
    ): array {
        $count = count($options);
        $values = array_values($options);

        $this->writeln('  ' . $this->style->apply($question, 'green'));
        $this->writeln('  ' . $this->style->apply(
            $multiple ? '↑↓ move · space select · enter confirm' : '↑↓ move · enter confirm',
            'gray'
        ));

        $terminal = new Terminal();
        $terminal->enterRawMode();

        try {
            $this->drawOptions($values, $cursor, $selected, $multiple, false);

            while (true) {
                $key = $terminal->readKey();

                if ($key === Terminal::ENTER) {
                    break;
                }

                if ($key === Terminal::CANCEL) {
                    $selected = $multiple ? $selected : [$cursor];
                    break;
                }

                if ($key === Terminal::UP) {
                    $cursor = ($cursor - 1 + $count) % $count;
                } elseif ($key === Terminal::DOWN) {
                    $cursor = ($cursor + 1) % $count;
                } elseif ($key === Terminal::SPACE && $multiple) {
                    $position = array_search($cursor, $selected, true);

                    $selected = $position === false
                        ? [...$selected, $cursor]
                        : array_values(array_diff($selected, [$cursor]));
                }

                $this->drawOptions($values, $cursor, $selected, $multiple, true);
            }
        } finally {
            $terminal->restore();
        }

        sort($selected);

        return $multiple ? $selected : [$cursor];
    }

    /**
     * Draw every option, rewriting the block in place after the first pass.
     *
     * @param array<int, string> $values
     * @param array<int, int>    $selected
     */
    protected function drawOptions(array $values, int $cursor, array $selected, bool $multiple, bool $redraw): void
    {
        if ($redraw) {
            // Back up over the block just drawn, so the menu stays on one spot
            // instead of scrolling a new copy for every keypress.
            $this->write("\e[" . count($values) . 'A');
        }

        foreach ($values as $index => $label) {
            $active = $index === $cursor;
            $ticked = in_array($index, $selected, true);

            $marker = $multiple
                ? ($ticked ? '[x]' : '[ ]')
                : ($active ? '❯' : ' ');

            $line = '  ' . $marker . ' ' . $label;

            $this->write("\e[2K" . ($active
                ? $this->style->apply($line, 'cyan', null, true)
                : $line) . PHP_EOL);
        }
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

    /**
     * Run $callback for each item, redrawing a progress bar as it goes.
     *
     * The bar is drawn on one line and rewritten in place, so it needs a
     * terminal; without one — a cron log, a CI job — it is skipped and only
     * the callback runs, rather than filling the log with thousands of
     * redraw escape sequences.
     *
     * @template TItem
     * @template TResult
     *
     * @param  iterable<TItem>            $items
     * @param  callable(TItem): TResult   $callback
     * @return array<int, TResult>
     */
    public function withProgressBar(iterable $items, callable $callback): array
    {
        $all = is_array($items) ? $items : iterator_to_array($items);
        $total = count($all);
        $draw = $total > 0 && $this->style->isDecorated() && $this->verbosity->allows(Verbosity::Normal);

        $results = [];
        $done = 0;

        if ($draw) {
            $this->drawBar(0, $total);
        }

        foreach ($all as $item) {
            $results[] = $callback($item);

            if ($draw) {
                $this->drawBar(++$done, $total);
            }
        }

        if ($draw) {
            $this->write(PHP_EOL);
        }

        return $results;
    }

    /** Redraw the bar in place, on the line it already occupies. */
    protected function drawBar(int $done, int $total): void
    {
        $width = 30;
        $filled = $total === 0 ? $width : (int) floor($width * $done / $total);
        $percent = $total === 0 ? 100 : (int) floor(100 * $done / $total);

        $this->write(sprintf(
            "\r  %s%s %3d%% (%d/%d)",
            $this->style->apply(str_repeat('█', $filled), 'green'),
            str_repeat('░', $width - $filled),
            $percent,
            $done,
            $total
        ));
    }

    // ─── Output plumbing ────────────────────────────────────────────────────

    /**
     * Write at $level, or drop it when the caller asked to hear less.
     *
     * Written through STDOUT rather than echo so a progress line can be
     * redrawn, which is also why muting has to be checked here: output
     * buffering does not reach it.
     */
    protected function write(string $text, Verbosity $level = Verbosity::Normal): void
    {
        if (! $this->verbosity->allows($level)) {
            return;
        }

        fwrite(STDOUT, $text);
    }

    protected function writeln(string $text, Verbosity $level = Verbosity::Normal): void
    {
        $this->write($text . PHP_EOL, $level);
    }

    protected function terminalWidth(): int
    {
        return Terminal::width();
    }
}
