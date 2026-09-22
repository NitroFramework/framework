<?php

namespace Nitro\Console\Events;

use Nitro\Console\Verbosity;

/**
 * Payload for command.starting and command.finished.
 *
 * The signature rather than the command object, for the reason
 * ProviderEvent gives about providers: a listener holding the instance could
 * run it a second time, and a command that runs once is worth more than the
 * convenience.
 *
 * $exitCode is null on command.starting, since the command has not run yet.
 */
class CommandEvent
{
    /**
     * @param string             $signature The signature that was invoked.
     * @param array<int, string> $arguments Arguments as they were given.
     * @param Verbosity          $verbosity The level this invocation runs at.
     * @param int|null           $exitCode  What it returned, once it has.
     */
    public function __construct(
        public readonly string $signature,
        public readonly array $arguments,
        public readonly Verbosity $verbosity,
        public readonly ?int $exitCode = null,
    ) {}

    /** The same event, carrying the code the command returned. */
    public function finished(int $exitCode): self
    {
        return new self($this->signature, $this->arguments, $this->verbosity, $exitCode);
    }

    /** Whether the command reported success. */
    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
