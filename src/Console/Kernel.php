<?php

namespace Nitro\Console;

/**
 * The console kernel — parses argv and dispatches to the CommandManager.
 */
class Kernel
{
    public function __construct(
        protected OutputFormatter $output,
        protected CommandManager $commandManager
    ) {}

    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);

        try {
            return $this->commandManager->resolve($commandName, $arguments);
        } catch (\Throwable $e) {
            // Any failure — unknown command, missing argument, command throwing —
            // exits non-zero so scripts and CI can detect it.
            $this->output->error("Error: " . $e->getMessage());
            $this->output->writeln("");
            $this->output->info("Run 'php nitro help' to see available commands.");
            return 1;
        }
    }
}
