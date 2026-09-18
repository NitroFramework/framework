<?php

namespace Nitro\Process;

use Closure;

/**
 * Describes a process before it runs.
 *
 *     Process::path(base_path())
 *         ->timeout(30)
 *         ->env(['APP_ENV' => 'testing'])
 *         ->run('php nitro migrate');
 */
class PendingProcess
{
    protected ?string $directory = null;

    /** @var array<string, string> */
    protected array $environment = [];

    protected ?string $input = null;

    protected int $timeout = 60;

    protected ?int $idleTimeout = null;

    protected bool $quietly = false;

    /** @var array<string, string> Values to hide from the recorded command. */
    protected array $secrets = [];

    public function __construct(
        protected Factory $factory,
    ) {}

    /** Directory to run in. */
    public function path(string $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    /** Seconds to allow before the process is killed; 0 for no limit. */
    public function timeout(int $seconds): static
    {
        $this->timeout = max(0, $seconds);

        return $this;
    }

    /** Seconds of silence to allow before giving up. */
    public function idleTimeout(int $seconds): static
    {
        $this->idleTimeout = max(0, $seconds);

        return $this;
    }

    /** Remove the time limit. */
    public function forever(): static
    {
        $this->timeout = 0;

        return $this;
    }

    /**
     * Environment variables for the process, on top of the inherited ones.
     *
     * @param array<string, string> $environment
     */
    public function env(array $environment): static
    {
        $this->environment = array_merge($this->environment, $environment);

        return $this;
    }

    /** Text to write to the process's standard input. */
    public function input(string $input): static
    {
        $this->input = $input;

        return $this;
    }

    /** Discard output rather than collecting it. */
    public function quietly(): static
    {
        $this->quietly = true;

        return $this;
    }

    /** Run the command and wait for it to finish. */
    public function run(string|array $command, ?Closure $output = null): ProcessResult
    {
        return $this->factory->execute($this->definition($command), $output);
    }

    /**
     * The definition the factory runs or matches a fake against.
     *
     * @return array<string, mixed>
     */
    public function definition(string|array $command): array
    {
        return [
            'command' => is_array($command) ? implode(' ', $command) : $command,
            'directory' => $this->directory,
            'environment' => $this->environment,
            'input' => $this->input,
            'timeout' => $this->timeout,
            'idle_timeout' => $this->idleTimeout,
            'quietly' => $this->quietly,
        ];
    }
}
