<?php

namespace Nitro\Console;

use Nitro\Console\Contracts\PromptsForMissingInput;
use Nitro\Console\Support\Arguments;
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

    /** How much this invocation asked to hear; set from -q/-v/-vv/-vvv. */
    protected Verbosity $verbosity = Verbosity::Normal;

    /** The flags that refuse interaction, whatever the terminal says. */
    private const NO_INTERACTION = ['--no-interaction', '-n'];

    /** Whether there is someone to answer a prompt. */
    protected bool $interactive = false;

    /** Runs another command from inside this one; null when nothing wired it. */
    private ?CommandRunner $runner = null;

    /** The command's behaviour. Return an exit code (0 = success). */
    abstract public function handle(): int;

    /**
     * Bind raw CLI arguments, wire the output, and run handle().
     *
     * $verbosity overrides what the arguments ask for, which is how a command
     * run by another inherits the caller's level.
     */
    public function run(array $argv, ?Verbosity $verbosity = null): int
    {
        $this->verbosity = $verbosity ?? Verbosity::fromArguments($argv);
        $this->style = new Style();
        $this->components = new Components($this->style);
        $this->components->setVerbosity($this->verbosity);

        $this->interactive = $this->verbosity !== Verbosity::Quiet
            && Arguments::without($argv, self::NO_INTERACTION) === $argv
            && self::hasTerminal();

        // Stripped before the signature is parsed, or -v reads as an unknown
        // option belonging to this command.
        $this->bind(Arguments::without(Verbosity::strip($argv), self::NO_INTERACTION));

        return (int) ($this->handle() ?? 0);
    }

    /**
     * Let this command run others. Wired by whatever dispatched it.
     */
    public function setRunner(CommandRunner $runner): void
    {
        $this->runner = $runner;
    }

    /** Force a verbosity, for a command being run by another. */
    public function setVerbosity(Verbosity $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    /**
     * Run $callback for each item behind a progress bar.
     *
     * @template TItem
     * @template TResult
     *
     * @param  iterable<TItem>          $items
     * @param  callable(TItem): TResult $callback
     * @return array<int, TResult>
     */
    protected function withProgressBar(iterable $items, callable $callback): array
    {
        return $this->components->withProgressBar($items, $callback);
    }

    // ─── Running other commands ─────────────────────────────────────────────

    /**
     * Run another command and return its exit code.
     *
     * Arguments are given either as they would be typed — `['--force']` — or
     * by name, `['--queue' => 'high', 'user' => 5]`, which is the spelling
     * most callers reach for.
     *
     * @param array<array-key, mixed> $arguments
     */
    protected function call(string $command, array $arguments = []): int
    {
        return $this->runCommand($command, $arguments, $this->verbosity);
    }

    /**
     * Run another command, discarding whatever it prints.
     *
     * @param array<array-key, mixed> $arguments
     */
    protected function callSilently(string $command, array $arguments = []): int
    {
        return $this->runCommand($command, $arguments, Verbosity::Quiet);
    }

    /** @param array<array-key, mixed> $arguments */
    private function runCommand(string $command, array $arguments, Verbosity $verbosity): int
    {
        if ($this->runner === null) {
            throw new RuntimeException(
                "[{$command}] cannot be run from here: this command was created without a runner, "
                . 'so it has no way to reach the others. Resolve commands through the CommandManager.'
            );
        }

        return $this->runner->call($command, Arguments::flatten($arguments), $verbosity);
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
        foreach (array_values($headers) as $i => $header) {
            $widths[$i] = Style::width((string) $header);
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, Style::width((string) $cell));
            }
        }

        $separator = '+' . implode('+', array_map(fn($width) => str_repeat('-', $width + 2), $widths)) . '+';
        $renderRow = function (array $cells) use ($widths): string {
            $line = '|';
            foreach (array_values($cells) as $i => $cell) {
                $cell = (string) $cell;
                $line .= ' ' . $cell . str_repeat(' ', max(($widths[$i] ?? 0) - Style::width($cell), 0)) . ' |';
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

    /**
     * Ask a question, optionally refusing an answer that does not pass.
     *
     * @param callable(string): ?string|null $validate Error message, or null to accept.
     */
    protected function ask(string $question, ?string $default = null, ?callable $validate = null): string
    {
        return $this->components->ask($question, $default, $validate);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->components->confirm($question, $default);
    }

    protected function secret(string $question): string
    {
        return $this->components->secret($question);
    }

    /**
     * Choose one option, with the arrow keys where the terminal allows it.
     *
     * @param array<array-key, string> $options
     */
    protected function select(string $question, array $options, int|string|null $default = null): string
    {
        return $this->components->select($question, $options, $default);
    }

    /**
     * Choose any number of options: space toggles, enter confirms.
     *
     * @param  array<array-key, string> $options
     * @param  array<int, string>       $default
     * @return array<int, string>
     */
    protected function multiselect(string $question, array $options, array $default = []): array
    {
        return $this->components->multiselect($question, $options, $default);
    }

    protected function choice(string $question, array $choices, int|string|null $default = null): string
    {
        return $this->components->choice($question, $choices, $default);
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    protected function writeln(string $text = '', Verbosity $level = Verbosity::Normal): void
    {
        if (! $this->verbosity->allows($level)) {
            return;
        }

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

        foreach ($definition['arguments'] as $argument) {
            $this->arguments[$argument['name']] = $argument['default'];
        }
        foreach ($definition['options'] as $option) {
            $this->options[$option['name']] = $option['default'];
        }

        $positional = [];
        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $operator = strpos($body, '=');
                $key = $operator === false ? $body : substr($body, 0, $operator);
                $value = $operator === false ? null : substr($body, $operator + 1);

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
                $operator    = strpos($body, '=');
                $short = $operator === false ? $body : substr($body, 0, $operator);
                $value = $operator === false ? null : substr($body, $operator + 1);

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
        foreach ($definition['arguments'] as $argument) {
            if (in_array($argument['mode'], ['array', 'array_required'], true)) {
                $this->arguments[$argument['name']] = array_slice($positional, $i);
                $i = count($positional);
            } elseif ($i < count($positional)) {
                $this->arguments[$argument['name']] = $positional[$i++];
            }
        }

        foreach ($definition['arguments'] as $argument) {
            $missing = $this->arguments[$argument['name']] === null || $this->arguments[$argument['name']] === [];

            if (! in_array($argument['mode'], ['required', 'array_required'], true) || ! $missing) {
                continue;
            }

            if ($this->canPromptFor($argument['name'])) {
                $this->arguments[$argument['name']] = $this->promptFor($argument['name']);

                continue;
            }

            throw new RuntimeException("Not enough arguments (missing: {$argument['name']}).");
        }
    }

    /**
     * Whether this command may ask for $name instead of failing without it.
     *
     * Three things must hold: the command opted in, the invocation did not
     * refuse interaction, and there is a terminal to answer. Without the last
     * one a prompt is a job that hangs until something kills it.
     */
    private function canPromptFor(string $name): bool
    {
        return $this instanceof PromptsForMissingInput && $this->interactive;
    }

    /** Ask for a missing argument, using the command's own wording if it gave one. */
    private function promptFor(string $name): string
    {
        $labels = $this instanceof PromptsForMissingInput
            ? $this->promptForMissingArgumentsUsing()
            : [];

        $answer = '';

        // An empty answer leaves the argument missing, which is the state we
        // are here to resolve, so keep asking rather than proceeding with null.
        while ($answer === '') {
            $answer = trim($this->components->ask($labels[$name] ?? "What is the {$name}?"));
        }

        return $answer;
    }

    /** Whether anything is listening on the other end of STDIN. */
    private static function hasTerminal(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return function_exists('posix_isatty') ? @posix_isatty(STDIN) : false;
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
