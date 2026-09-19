<?php

namespace Nitro\Console\Concerns;

use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * The --force gate a destructive command must pass in production.
 *
 * Written once because it was previously written per command: each destructive
 * verb carried its own `if (env === production && ! --force)`, so whether a new
 * one was guarded depended on whether its author remembered. The check has to
 * live somewhere a command can only opt out of deliberately.
 *
 * The using class supplies $output and $config; every console command already
 * has both.
 */
trait ConfirmsInProduction
{
    /**
     * Whether a destructive command may proceed.
     *
     * Outside production, always. In production, only with --force — and the
     * refusal names the exact command to re-run, because a guard that makes the
     * operator reconstruct the invocation from memory is a guard they will
     * route around with a shell alias.
     *
     * @param string             $action    What is about to happen, e.g. "drop every table".
     * @param array<int, string> $arguments The invocation's arguments.
     * @param string             $reinvoke  The command to suggest, without --force.
     */
    protected function confirmToProceed(string $action, array $arguments, string $reinvoke): bool
    {
        if (! $this->runningInProduction()) {
            return true;
        }

        if (in_array('--force', $arguments, true)) {
            return true;
        }

        $this->confirmationOutput()->error(
            "Refusing to {$action} in production without --force."
            . "\nRe-run as: php nitro {$reinvoke} --force"
        );

        return false;
    }

    /** Whether the application is configured as production. */
    protected function runningInProduction(): bool
    {
        $config = $this->confirmationConfig();

        return $config !== null && $config->get('app.env') === 'production';
    }

    /**
     * The formatter the refusal is written to.
     *
     * Read off the using class rather than injected, so the trait adds nothing
     * to a command's constructor.
     */
    private function confirmationOutput(): OutputFormatter
    {
        return $this->output;
    }

    /** The config the environment is read from, or null if the command has none. */
    private function confirmationConfig(): ?ConfigRepository
    {
        return property_exists($this, 'config') && $this->config instanceof ConfigRepository
            ? $this->config
            : null;
    }
}
