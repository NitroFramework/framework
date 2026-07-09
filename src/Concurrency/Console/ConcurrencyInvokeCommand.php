<?php

namespace Nitro\Concurrency\Console;

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

    public function getCommands(): array
    {
        return [
            'concurrency:invoke' => 'Internal: run a serialized concurrency task (used by the process driver)',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        // Write the raw sentinel to STDOUT so it reaches the parent's pipe intact,
        // bypassing any output buffering/decoration the console may apply.
        fwrite(STDOUT, $this->render($arguments[0] ?? ''));
    }

    /** Run the base64 task payload and build the sentinel-wrapped result string. */
    public function render(string $payload): string
    {
        try {
            $task   = unserialize(base64_decode($payload));
            $result = TaskInvoker::invoke($task);
            $out    = ['ok' => true, 'result' => base64_encode(serialize($result))];
        } catch (\Throwable $e) {
            $out = ['ok' => false, 'error' => $e->getMessage()];
        }

        return self::OPEN . base64_encode(json_encode($out)) . self::CLOSE;
    }
}
