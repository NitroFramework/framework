<?php

namespace Nitro\Facades;

/**
 * Process facade — runs external commands.
 *
 *   Process::run('git status')->output();
 *   Process::path(base_path())->run('npm run build');
 *
 * @method static \Nitro\Process\ProcessResult run(string|array $command, ?\Closure $output = null)
 * @method static \Nitro\Process\PendingProcess path(string $directory)
 * @method static \Nitro\Process\PendingProcess timeout(int $seconds)
 * @method static \Nitro\Process\PendingProcess idleTimeout(int $seconds)
 * @method static \Nitro\Process\PendingProcess forever()
 * @method static \Nitro\Process\PendingProcess env(array $environment)
 * @method static \Nitro\Process\PendingProcess input(string $input)
 * @method static \Nitro\Process\PendingProcess quietly()
 * @method static \Nitro\Process\Factory fake(array|\Closure|\Nitro\Process\ProcessResult|null $stubs = null)
 * @method static \Nitro\Process\Factory preventStrayProcesses(bool $prevent = true)
 * @method static \Nitro\Process\ProcessResult result(string $output = '', int $exitCode = 0, string $errorOutput = '')
 * @method static void assertRan(\Closure $callback)
 * @method static void assertDidntRun(\Closure $callback)
 * @method static void assertNothingRan()
 * @method static void assertRanCount(int $count)
 */
class Process extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'process';
    }
}
