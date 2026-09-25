<?php

namespace Nitro\Console\Commands;

use Illuminate\Console\Command;
use Nitro\Console\Optimizer;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan route:cache`: compiles app + package routes into Nitro's route table, and the
 * container factories for every controller / middleware those routes build.
 */
#[AsCommand(name: 'route:cache')]
class RouteCacheCommand extends Command
{
    protected $signature = 'route:cache';

    protected $description = 'Create a route cache file for faster route registration';

    public function handle(): int
    {
        $this->callSilent('route:clear');

        $optimizer = new Optimizer($this->laravel);

        try {
            [$table, $factories] = $optimizer->cacheRoutes($optimizer->fresh());
        } finally {
            $optimizer->restore();
        }

        $this->components->info(sprintf(
            'Routes cached successfully (%d routes, %d container factories compiled).',
            count($table['routes']),
            $factories->count()
        ));

        return self::SUCCESS;
    }
}
