<?php

namespace Nitro\Process;

use RuntimeException;

/**
 * What a finished process left behind.
 *
 *     $result = Process::run('git status');
 *
 *     $result->successful();
 *     $result->output();
 */
class ProcessResult
{
    public function __construct(
        protected string $command = '',
        protected int $exitCode = 0,
        protected string $output = '',
        protected string $errorOutput = '',
    ) {}

    public function command(): string
    {
        return $this->command;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /** Whether the output contains the given text. */
    public function seeInOutput(string $text): bool
    {
        return str_contains($this->output, $text);
    }

    public function seeInErrorOutput(string $text): bool
    {
        return str_contains($this->errorOutput, $text);
    }

    /**
     * Raise when the process failed.
     *
     * @param  callable|null $callback Given the result and exception first.
     * @throws ProcessFailedException
     */
    public function throw(?callable $callback = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        $exception = new ProcessFailedException($this);

        if ($callback !== null) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    /** Raise when the process failed and the condition holds. */
    public function throwIf(mixed $condition): static
    {
        $condition = is_callable($condition) ? $condition($this) : $condition;

        return $condition ? $this->throw() : $this;
    }

    public function __toString(): string
    {
        return $this->output;
    }
}
