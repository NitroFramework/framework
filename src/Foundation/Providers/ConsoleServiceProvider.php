<?php

namespace Nitro\Foundation\Providers;

use Nitro\Console\CommandManager;
use Nitro\Console\CommandRunner;
use Nitro\Console\Kernel;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the console kernel and command bindings.
 */
class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(OutputFormatter::class);
        $this->container->singleton(CommandManager::class);
        $this->container->singleton(Kernel::class);

        // So a grouped command can take the runner in its constructor and run
        // another command, the way a Command does through $this->call().
        $this->container->alias(CommandManager::class, CommandRunner::class);
    }
}
