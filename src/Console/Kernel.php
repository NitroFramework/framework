<?php

namespace Nitro\Console;

use Illuminate\Foundation\Console\Kernel as LaravelKernel;
use Nitro\Foundation\Bootstrap;

/**
 * Laravel's console kernel: `php artisan` with every framework command (make:*, migrate,
 * queue:*, schedule:*, vendor:publish, ...), routes/console.php and package commands.
 *
 * Nitro swaps in its bootstrap and its own `optimize` / `optimize:clear` / `route:cache`, which
 * build Nitro's compiled caches (routes, container factories).
 */
class Kernel extends LaravelKernel
{
    protected $commands = [
        Commands\OptimizeCommand::class,
        Commands\OptimizeClearCommand::class,
        Commands\RouteCacheCommand::class,
        Commands\RouteClearCommand::class,
        Commands\ViewWarmCommand::class,
        Commands\NitroOptimizeCommand::class,
        Commands\NitroClearCommand::class,
    ];

    protected function bootstrappers()
    {
        return Bootstrap::bootstrappers(console: true);
    }

    /**
     * Laravel only discovers app/Console/Commands and routes/console.php when the kernel is its
     * own class (not an app subclass with a commands() method). Nitro's kernel is a drop-in.
     */
    protected function shouldDiscoverCommands()
    {
        return true;
    }
}
