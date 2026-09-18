<?php

namespace Nitro\Process;

use Closure;
use RuntimeException;

/**
 * Runs external commands, and the place they can be faked.
 *
 *     Process::run('git status')->output();
 *
 *     Process::fake(['git *' => Process::result('nothing to commit')]);
 *     Process::assertRan(fn ($process) => str_contains($process['command'], 'git'));
 *
 * @method static ProcessResult run(string|array $command, ?Closure $output = null)
 * @method static PendingProcess path(string $directory)
 * @method static PendingProcess timeout(int $seconds)
 * @method static PendingProcess env(array $environment)
 * @method static PendingProcess input(string $input)
 * @method static PendingProcess quietly()
 */
class Factory
{
    /**
     * Canned results while faking, keyed by command pattern.
     *
     * @var array<string, Closure|ProcessResult>|null Null when not faking.
     */
    protected ?array $stubs = null;

    /** @var array<int, array<string, mixed>> */
    protected array $recorded = [];

    protected bool $preventStrayProcesses = false;

    // ─── Faking ───────────────────────────────────────────

    /**
     * Stop commands reaching the operating system and answer them from $stubs.
     *
     * A key is a command pattern where '*' matches any run of characters.
     *
     * @param array<string, Closure|ProcessResult|string>|Closure|ProcessResult|null $stubs
     */
    public function fake(array|Closure|ProcessResult|null $stubs = null): static
    {
        $this->recorded = [];

        $this->stubs = match (true) {
            $stubs === null => ['*' => $this->result()],
            $stubs instanceof Closure, $stubs instanceof ProcessResult => ['*' => $stubs],
            default => $stubs,
        };

        return $this;
    }

    public function stopFaking(): static
    {
        $this->stubs = null;
        $this->recorded = [];

        return $this;
    }

    public function isFaking(): bool
    {
        return $this->stubs !== null;
    }

    /** Fail rather than run a command no stub matched. */
    public function preventStrayProcesses(bool $prevent = true): static
    {
        $this->preventStrayProcesses = $prevent;

        return $this;
    }

    /** Build a canned result for a stub. */
    public function result(string $output = '', int $exitCode = 0, string $errorOutput = ''): ProcessResult
    {
        return new ProcessResult('', $exitCode, $output, $errorOutput);
    }

    // ─── Assertions ───────────────────────────────────────

    /**
     * @param  Closure(array<string, mixed>, ProcessResult): bool $callback
     * @throws RuntimeException When nothing matched.
     */
    public function assertRan(Closure $callback): void
    {
        foreach ($this->recorded as $entry) {
            if ($callback($entry['process'], $entry['result'])) {
                return;
            }
        }

        throw new RuntimeException('No recorded process matched the given callback.');
    }

    /**
     * @param  Closure(array<string, mixed>, ProcessResult): bool $callback
     * @throws RuntimeException When one matched.
     */
    public function assertDidntRun(Closure $callback): void
    {
        foreach ($this->recorded as $entry) {
            if ($callback($entry['process'], $entry['result'])) {
                throw new RuntimeException('A recorded process matched the given callback.');
            }
        }
    }

    /** @throws RuntimeException When any command ran. */
    public function assertNothingRan(): void
    {
        if ($this->recorded !== []) {
            throw new RuntimeException(
                'Expected no processes, but ' . count($this->recorded) . ' ran.'
            );
        }
    }

    /** @throws RuntimeException When the count does not match. */
    public function assertRanCount(int $count): void
    {
        $actual = count($this->recorded);

        if ($actual !== $count) {
            throw new RuntimeException("Expected {$count} processes, but {$actual} ran.");
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    // ─── Running ──────────────────────────────────────────

    /** A description carrying none of the previous one's settings. */
    public function newPendingProcess(): PendingProcess
    {
        return new PendingProcess($this);
    }

    /**
     * Run a described command, or answer it from a stub while faking.
     *
     * @param  array<string, mixed> $process
     * @throws RuntimeException When a stray command is prevented.
     */
    public function execute(array $process, ?Closure $output = null): ProcessResult
    {
        if ($this->stubs !== null) {
            $result = $this->stubFor($process);

            $this->recorded[] = ['process' => $process, 'result' => $result];

            return $result;
        }

        return $this->spawn($process, $output);
    }

    /**
     * @param  array<string, mixed> $process
     * @throws RuntimeException When nothing matched and strays are prevented.
     */
    protected function stubFor(array $process): ProcessResult
    {
        foreach ($this->stubs ?? [] as $pattern => $stub) {
            if (! $this->commandMatches($pattern, $process['command'])) {
                continue;
            }

            $result = $stub instanceof Closure ? $stub($process) : $stub;

            if (is_string($result)) {
                $result = $this->result($result);
            }

            return new ProcessResult(
                $process['command'],
                $result->exitCode(),
                $result->output(),
                $result->errorOutput()
            );
        }

        if ($this->preventStrayProcesses) {
            throw new RuntimeException(
                "No stub matched [{$process['command']}] and stray processes are prevented."
            );
        }

        return new ProcessResult($process['command']);
    }

    protected function commandMatches(string $pattern, string $command): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $pattern = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return preg_match('#^' . $pattern . '$#i', $command) === 1;
    }

    /**
     * Start the command and collect what it produces.
     *
     * @param  array<string, mixed> $process
     * @throws RuntimeException When the command could not be started.
     */
    protected function spawn(array $process, ?Closure $output = null): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = $process['environment'] === []
            ? null
            : array_merge(getenv(), $process['environment']);

        $handle = @proc_open(
            $process['command'],
            $descriptors,
            $pipes,
            $process['directory'],
            $environment
        );

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not start [{$process['command']}].");
        }

        if ($process['input'] !== null) {
            fwrite($pipes[0], $process['input']);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        while (true) {
            $stdout .= $this->drain($pipes[1], $output, 'out', $process['quietly']);
            $stderr .= $this->drain($pipes[2], $output, 'err', $process['quietly']);

            $status = proc_get_status($handle);

            if (! $status['running']) {
                break;
            }

            if ($process['timeout'] > 0 && (microtime(true) - $startedAt) > $process['timeout']) {
                proc_terminate($handle);

                $stdout .= $this->drain($pipes[1], $output, 'out', $process['quietly']);
                $stderr .= $this->drain($pipes[2], $output, 'err', $process['quietly']);

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($handle);

                return new ProcessResult(
                    $process['command'],
                    124,
                    $stdout,
                    trim($stderr . "\nThe process exceeded its timeout of {$process['timeout']}s.")
                );
            }

            usleep(1000);
        }

        $stdout .= $this->drain($pipes[1], $output, 'out', $process['quietly']);
        $stderr .= $this->drain($pipes[2], $output, 'err', $process['quietly']);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($handle);

        return new ProcessResult($process['command'], $exitCode, $stdout, $stderr);
    }

    /** Read whatever a pipe has ready, handing it to the callback as it arrives. */
    private function drain(mixed $pipe, ?Closure $output, string $channel, bool $quietly): string
    {
        $chunk = stream_get_contents($pipe);

        if (! is_string($chunk) || $chunk === '') {
            return '';
        }

        if ($output !== null) {
            $output($channel, $chunk);
        }

        return $quietly ? '' : $chunk;
    }

    /**
     * Forward configuration calls to a fresh description.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->newPendingProcess()->{$method}(...$arguments);
    }
}
