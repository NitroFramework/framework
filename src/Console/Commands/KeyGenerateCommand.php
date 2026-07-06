<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;

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

    public function getCommands(): array
    {
        return [
            'key:generate' => 'Set the application key (APP_KEY) in the .env file',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $show  = in_array('--show', $arguments, true);
        $force = in_array('--force', $arguments, true);

        $key = $this->generateKey();

        if ($show) {
            $this->output->writeln($key);

            return;
        }

        $path = $this->paths->base('.env');

        if (!is_file($path)) {
            $this->output->error('No .env file found. Copy .env.example to .env first.');

            return;
        }

        $contents = (string) file_get_contents($path);

        // Refuse to clobber an existing key unless --force, so a stray run can't
        // silently invalidate every already-encrypted payload / signed session.
        if (!$force && preg_match('/^APP_KEY=.+$/m', $contents)) {
            $this->output->warning('Application key already set. Use --force to overwrite it.');

            return;
        }

        if (preg_match('/^APP_KEY=.*$/m', $contents)) {
            $contents = preg_replace_callback(
                '/^APP_KEY=.*$/m',
                static fn (): string => 'APP_KEY=' . $key,
                $contents,
                1,
            );
        } else {
            $contents = rtrim($contents, "\n") . "\nAPP_KEY=" . $key . "\n";
        }

        if (file_put_contents($path, $contents) === false) {
            $this->output->error('Unable to write the application key to .env.');

            return;
        }

        $this->output->success('Application key set successfully.');
    }

    /** A 256-bit random key in Laravel's `base64:` envelope. */
    protected function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
