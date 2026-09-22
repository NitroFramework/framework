<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\PathRegistry;

/**
 * Generate the application key used to derive Nitro's encryption/HMAC secrets
 * (the hx-vals encryptor, Livewire checksums, auto-state signatures). Mirrors
 * Laravel's `key:generate`: writes a fresh `base64:` key into the project's
 * `.env`, or prints it with `--show`.
 */
class KeyGenerateCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'key:generate' => 'Set the application key (APP_KEY) in the .env file',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $signature, array $arguments): int
    {
        $show  = in_array('--show', $arguments, true);
        $force = in_array('--force', $arguments, true);

        $key = $this->generateKey();

        if ($show) {
            $this->output->writeln($key);

            return ExitCode::SUCCESS;
        }

        $path = $this->paths->base('.env');

        if (!is_file($path)) {
            $this->output->error('No .env file found. Copy .env.example to .env first.');

            return ExitCode::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        /*
         * Handled line by line rather than with a multiline pattern, because
         * neither anchor behaves on a CRLF file: `.` matches the carriage
         * return, so an empty APP_KEY= reads as a one-character value and looks
         * like a key that is already set, while `$` only matches before a
         * newline, so a pattern excluding \r never matches the line at all. A
         * .env edited through a browser is CRLF — that is how HTML submits a
         * textarea — so a hosting panel's environment editor produces exactly
         * this file.
         */
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        $index = null;
        $current = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^APP_KEY=(.*)$/', $line, $matches) === 1) {
                $index = $number;
                $current = $matches[1];
                break;
            }
        }

        // Refuse to clobber an existing key unless --force, so a stray run can't
        // silently invalidate every already-encrypted payload / signed session.
        if (!$force && $current !== null && trim($current) !== '') {
            $this->output->warning('Application key already set. Use --force to overwrite it.');

            return ExitCode::FAILURE;
        }

        if ($index !== null) {
            $lines[$index] = 'APP_KEY=' . $key;
        } elseif (end($lines) === '') {
            // Keep the file's trailing newline where it was.
            array_splice($lines, count($lines) - 1, 0, ['APP_KEY=' . $key]);
        } else {
            $lines[] = 'APP_KEY=' . $key;
            $lines[] = '';
        }

        $contents = implode($newline, $lines);

        if (file_put_contents($path, $contents) === false) {
            $this->output->error('Unable to write the application key to .env.');

            return ExitCode::FAILURE;
        }

        $this->output->success('Application key set successfully.');

        return ExitCode::SUCCESS;
    }

    /** A 256-bit random key in Laravel's `base64:` envelope. */
    protected function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
