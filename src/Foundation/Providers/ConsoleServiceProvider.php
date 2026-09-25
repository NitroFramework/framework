<?php

namespace Nitro\Foundation\Providers;

use Nitro\Console\CommandManager;
use Nitro\Console\CommandRunner;
use Nitro\Console\Kernel;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Register the console kernel and command bindings.
 */
class ConsoleServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [OutputFormatter::class, CommandManager::class, CommandRunner::class, Kernel::class];
    }

    /**
     * Register the kernel, and the command manager as the runner a command uses to call another.
     */
    public function register(): void
    {
        $this->container->singleton(OutputFormatter::class);
        $this->container->singleton(CommandManager::class);
        $this->container->singleton(Kernel::class);
        $this->container->alias(CommandManager::class, CommandRunner::class);
    }
}
