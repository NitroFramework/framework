<?php

namespace Nitro\Foundation\Providers;

use Nitro\Console\CommandManager;
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
        $this->container->singleton(OutputFormatter::class, OutputFormatter::class);
        $this->container->singleton(CommandManager::class, CommandManager::class);
        $this->container->singleton(Kernel::class, Kernel::class);
    }
}
