<?php

namespace Nitro\Concurrency\Console;

use Nitro\Console\ExitCode;
use Nitro\Concurrency\TaskInvoker;
use Nitro\Console\Contracts\CommandInterface;

/**
 * Internal entrypoint for the process concurrency driver. The parent spawns
 * `php nitro concurrency:invoke <base64-task>`; this boots the app, runs the task,
 * and prints the serialized result wrapped in sentinels so the parent can extract
 * it regardless of any other console output. Hidden — not for direct use.
 */
class ConcurrencyInvokeCommand implements CommandInterface
{
    /** The sentinel wrapper the ProcessDriver looks for. Keep in sync there. */
    public const OPEN = '@@NC@@';
    public const CLOSE = '@@/NC@@';

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'concurrency:invoke' => 'Internal: run a serialized concurrency task (used by the process driver)',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments = []): int
    {
        // Write the raw sentinel to STDOUT so it reaches the parent's pipe intact,
        // bypassing any output buffering/decoration the console may apply.
        fwrite(STDOUT, $this->render($arguments[0] ?? ''));

        // Always 0: the task's own success or failure is carried inside the
        // sentinel the parent parses, so a non-zero exit here would mean the
        // invoker itself broke, not the task.
        return ExitCode::SUCCESS;
    }

    /** Run the base64 task payload and build the sentinel-wrapped result string. */
    public function render(string $payload): string
    {
        try {
            $task   = unserialize(base64_decode($payload));
            $result = TaskInvoker::invoke($task);
            $out    = ['ok' => true, 'result' => base64_encode(serialize($result))];
        } catch (\Throwable $exception) {
            $out = ['ok' => false, 'error' => $exception->getMessage()];
        }

        return self::OPEN . base64_encode(json_encode($out)) . self::CLOSE;
    }
}
