<?php

namespace Nitro\Console;

use Illuminate\Foundation\Console\Kernel as BaseKernel;
use Nitro\Console\Commands\EloquentCacheCommand;
use Nitro\Console\Commands\EloquentClearCommand;
use Nitro\Console\Commands\NitroClearCommand;
use Nitro\Console\Commands\NitroOptimizeCommand;
use Nitro\Console\Commands\OptimizeClearCommand;
use Nitro\Console\Commands\OptimizeCommand;
use Nitro\Console\Commands\RouteCacheCommand;
use Nitro\Console\Commands\RouteClearCommand;
use Nitro\Console\Commands\ViewWarmCommand;
use Nitro\Foundation\Bootstrap;

/**
 * Laravel's console kernel: `php artisan` with every framework command (make:*, migrate,
 * queue:*, schedule:*, vendor:publish, ...), routes/console.php and package commands.
 *
 * Nitro swaps in its bootstrap and its own `optimize` / `optimize:clear` / `route:cache`, which
 * build Nitro's compiled caches (routes, container factories, Eloquent).
 */
class Kernel extends BaseKernel
{
    protected $commands = [
        OptimizeCommand::class,
        OptimizeClearCommand::class,
        RouteCacheCommand::class,
        RouteClearCommand::class,
        EloquentCacheCommand::class,
        EloquentClearCommand::class,
        ViewWarmCommand::class,
        NitroOptimizeCommand::class,
        NitroClearCommand::class,
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
