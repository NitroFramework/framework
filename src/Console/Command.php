<?php

namespace Nitro\Console;

use Nitro\Console\Support\SignatureParser;
use Nitro\Console\Support\Style;
use Nitro\Console\View\Components;
use RuntimeException;

/**
 * Base class for application (and framework) console commands, mirroring
 * Laravel's command API: a `$signature` string that declares the name,
 * arguments and options, a `handle()` method, argument()/option() input
 * accessors, and the full styled output surface (info/error/warn/line/table
 * plus the modern $this->components badges, tasks and two-column details).
 *
 *   class SendReports extends Command
 *   {
 *       protected string $signature = 'reports:send {user} {--queue}';
 *       protected string $description = 'Send the report to a user';
 *
 *       public function handle(): int
 *       {
 *           $this->components->info('Sending to ' . $this->argument('user'));
 *           return 0;
 *       }
 *   }
 */
abstract class Command
{
    /** The command signature: "name {arg} {arg?} {--opt=}". */
    protected string $signature = '';

    /** One-line description shown in `php nitro help`. */
    protected string $description = '';

    /** Parsed signature (name + argument/option definitions). */
    private array $definitionCache = [];

    /** Resolved input. */
    private array $arguments = [];
    private array $options = [];

    protected Style $style;

    /** The modern component UI ($this->components->info/task/twoColumnDetail/…). */
    protected Components $components;

    /** The command's behaviour. Return an exit code (0 = success). */
    abstract public function handle(): int;

    /** Bind raw CLI arguments, wire the output, and run handle(). */
    public function run(array $argv): int
    {
        $this->style = new Style();
        $this->components = new Components($this->style);
        $this->bind($argv);

        return (int) ($this->handle() ?? 0);
    }

    // ─── Metadata (used by the CommandManager) ──────────────────────────────

    public function getName(): string
    {
        return $this->definition()['name'];
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    // ─── Input ──────────────────────────────────────────────────────────────

    public function argument(?string $key = null): mixed
    {
        return $key === null ? $this->arguments : ($this->arguments[$key] ?? null);
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(?string $key = null): mixed
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function options(): array
    {
        return $this->options;
    }

    public function hasOption(string $key): bool
    {
        return array_key_exists($key, $this->options);
    }

    // ─── Output ─────────────────────────────────────────────────────────────

    protected function line(string $text = ''): void
    {
        $this->writeln($text);
    }

    protected function info(string $text): void
    {
        $this->writeln("<info>{$text}</info>");
    }

    protected function comment(string $text): void
    {
        $this->writeln("<comment>{$text}</comment>");
    }

    protected function warn(string $text): void
    {
        $this->writeln("<warning>{$text}</warning>");
    }

    protected function error(string $text): void
    {
        $this->writeln("<error>{$text}</error>");
    }

    protected function newLine(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            fwrite(STDOUT, PHP_EOL);
        }
    }

    protected function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach (array_values($headers) as $i => $h) {
            $widths[$i] = Style::width((string) $h);
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $c) {
                $widths[$i] = max($widths[$i] ?? 0, Style::width((string) $c));
            }
        }

        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $renderRow = function (array $cells) use ($widths): string {
            $line = '|';
            foreach (array_values($cells) as $i => $c) {
                $c = (string) $c;
                $line .= ' ' . $c . str_repeat(' ', max(($widths[$i] ?? 0) - Style::width($c), 0)) . ' |';
            }
            return $line;
        };

        $this->writeln($separator);
        $this->writeln($renderRow($headers));
        $this->writeln($separator);
        foreach ($rows as $row) {
            $this->writeln($renderRow($row));
        }
        $this->writeln($separator);
    }

    // ─── Interaction ────────────────────────────────────────────────────────

    protected function ask(string $question, ?string $default = null): string
    {
        return $this->components->ask($question, $default);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->components->confirm($question, $default);
    }

    protected function secret(string $question): string
    {
        return $this->components->secret($question);
    }

    protected function choice(string $question, array $choices, int|string|null $default = null): string
    {
        return $this->components->choice($question, $choices, $default);
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    protected function writeln(string $text = ''): void
    {
        fwrite(STDOUT, $this->style->format($text) . PHP_EOL);
    }

    private function definition(): array
    {
        return $this->definitionCache !== []
            ? $this->definitionCache
            : ($this->definitionCache = SignatureParser::parse($this->signature));
    }

    /** Parse raw CLI arguments against the signature definition. */
    private function bind(array $argv): void
    {
        $definition = $this->definition();

        foreach ($definition['arguments'] as $a) {
            $this->arguments[$a['name']] = $a['default'];
        }
        foreach ($definition['options'] as $o) {
            $this->options[$o['name']] = $o['default'];
        }

        $positional = [];
        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $eq = strpos($body, '=');
                $key = $eq === false ? $body : substr($body, 0, $eq);
                $value = $eq === false ? null : substr($body, $eq + 1);

                $option = $this->findOption($key);
                if ($option === null) {
                    continue;
                }
                if ($option['mode'] === 'none') {
                    $this->options[$key] = true;
                } elseif ($option['mode'] === 'array') {
                    $this->options[$key] = array_merge((array) $this->options[$key], [$value]);
                } else {
                    // A value-mode option given bare (no '=') takes its default,
                    // NOT boolean true — otherwise a string consumer gets a bool.
                    $this->options[$key] = $value ?? ($option['default'] ?? null);
                }
            } elseif (str_starts_with($token, '-') && strlen($token) > 1 && !is_numeric($token)) {
                // Short option: support -n and -n=value (not just valueless -n).
                // is_numeric guard so a negative-number argument like -5 falls
                // through to positional instead of being swallowed.
                $body  = substr($token, 1);
                $eq    = strpos($body, '=');
                $short = $eq === false ? $body : substr($body, 0, $eq);
                $value = $eq === false ? null : substr($body, $eq + 1);

                $option = $this->findOptionByShortcut($short);
                if ($option !== null) {
                    if ($option['mode'] === 'none') {
                        $this->options[$option['name']] = true;
                    } elseif ($option['mode'] === 'array') {
                        $this->options[$option['name']] = array_merge((array) $this->options[$option['name']], [$value]);
                    } else {
                        $this->options[$option['name']] = $value ?? ($option['default'] ?? null);
                    }
                }
            } else {
                $positional[] = $token;
            }
        }

        $i = 0;
        foreach ($definition['arguments'] as $a) {
            if (in_array($a['mode'], ['array', 'array_required'], true)) {
                $this->arguments[$a['name']] = array_slice($positional, $i);
                $i = count($positional);
            } elseif ($i < count($positional)) {
                $this->arguments[$a['name']] = $positional[$i++];
            }
        }

        foreach ($definition['arguments'] as $a) {
            $missing = $this->arguments[$a['name']] === null || $this->arguments[$a['name']] === [];
            if (in_array($a['mode'], ['required', 'array_required'], true) && $missing) {
                throw new RuntimeException("Not enough arguments (missing: {$a['name']}).");
            }
        }
    }

    private function findOption(string $name): ?array
    {
        foreach ($this->definition()['options'] as $option) {
            if ($option['name'] === $name) {
                return $option;
            }
        }

        return null;
    }

    private function findOptionByShortcut(string $shortcut): ?array
    {
        foreach ($this->definition()['options'] as $option) {
            if ($option['shortcut'] === $shortcut) {
                return $option;
            }
        }

        return null;
    }
}
