<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\ExitCode;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\MaintenanceMode;

/**
 * `php nitro down` / `php nitro up` — the switch in front of
 * {@see \Nitro\Http\Middleware\PreventRequestsDuringMaintenance}.
 *
 *   php nitro down --retry=60 --secret=let-me-in --message="Back at 14:00"
 *   php nitro up
 *
 * The secret is what makes this usable rather than theatrical: visiting
 * /let-me-in sets a cookie and redirects to the root, so whoever is verifying
 * the deploy can browse the live site normally while everyone else gets a 503.
 */
class MaintenanceCommands implements CommandInterface
{
    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
        'down' => 'Put the application into maintenance mode (--retry= --secret= --message=)',
        'up'   => 'Bring the application out of maintenance mode',
    ];

    public function __construct(
        private OutputFormatter $output,
        private MaintenanceMode $maintenance,
    ) {}

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments): int
    {
        return match ($command) {
            'down'  => $this->down($arguments),
            'up'    => $this->up(),
            default => $this->invalidSignature("Unknown maintenance command: {$command}"),
        };
    }

    /** @param array<int, string> $arguments */
    private function down(array $arguments): int
    {
        if ($this->maintenance->active()) {
            $this->output->warning('Application is already down.');

            return ExitCode::SUCCESS;
        }

        $payload = array_filter([
            'retry'   => $this->value($arguments, '--retry'),
            'secret'  => $this->value($arguments, '--secret'),
            'message' => $this->value($arguments, '--message'),
        ], static fn ($value): bool => $value !== null);

        $this->maintenance->activate($payload);

        if (! $this->maintenance->active()) {
            $this->output->error('Could not write the maintenance file. Check storage/framework is writable.');

            return ExitCode::FAILURE;
        }

        $this->output->success('Application is now in maintenance mode.');

        if (isset($payload['secret'])) {
            $this->output->info("  Bypass by visiting: /{$payload['secret']}");
        }

        if (isset($payload['retry'])) {
            $this->output->info("  Retry-After: {$payload['retry']}s");
        }

        return ExitCode::SUCCESS;
    }

    private function up(): int
    {
        if (! $this->maintenance->active()) {
            $this->output->warning('Application is already up.');

            return ExitCode::SUCCESS;
        }

        $this->maintenance->deactivate();

        if ($this->maintenance->active()) {
            $this->output->error('Could not remove the maintenance file.');

            return ExitCode::FAILURE;
        }

        $this->output->success('Application is now live.');

        return ExitCode::SUCCESS;
    }

    /**
     * Read `--flag=value` from the raw arguments.
     *
     * @param array<int, string> $arguments
     */
    private function value(array $arguments, string $flag): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $flag . '=')) {
                $value = substr($argument, strlen($flag) + 1);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
