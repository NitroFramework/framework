<?php

namespace Nitro\Console\Commands;

use Illuminate\Foundation\Console\OptimizeCommand as LaravelOptimizeCommand;
use Nitro\Console\Optimizer;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's optimize (config, events, routes, views, package optimize commands), where
 * route:cache is Nitro's compiled table + container factories.
 *
 *   php artisan optimize --profile   also times every eager service provider
 */
#[AsCommand(name: 'optimize')]
class OptimizeCommand extends LaravelOptimizeCommand
{
    protected $signature = 'optimize
        {--except= : The commands to skip}
        {--profile : Time each eager service provider (register + boot)}';

    public function handle()
    {
        parent::handle();

        if (! $this->option('profile')) {
            return;
        }

        $optimizer = new Optimizer($this->laravel);

        try {
            $timings = $optimizer->fresh(profile: true)->providerTimings();
        } finally {
            $optimizer->restore();
        }

        $this->printProfile($timings);
    }

    private function printProfile(array $timings): void
    {
        $this->newLine();
        $this->components->info('Eager service providers (run on every request).');

        uasort($timings, fn ($a, $b) => ($b['register'] + $b['boot']) <=> ($a['register'] + $a['boot']));

        $rows = [];

        foreach ($timings as $provider => $t) {
            $rows[] = [$provider, sprintf('%.3f', $t['register']), sprintf('%.3f', $t['boot']), sprintf('%.3f', $t['register'] + $t['boot'])];
        }

        $this->table(['Provider', 'register ms', 'boot ms', 'total ms'], $rows);
        $this->line('  Measured in this console process, where classes may already be loaded: web requests can cost more.');
    }
}
