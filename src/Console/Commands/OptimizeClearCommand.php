<?php

namespace Nitro\Console\Commands;

use Illuminate\Foundation\Console\OptimizeClearCommand as BaseOptimizeClearCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's optimize:clear, plus Nitro's compiled container factories and Eloquent.
 */
#[AsCommand(name: 'optimize:clear')]
class OptimizeClearCommand extends BaseOptimizeClearCommand
{
    /**
     * Laravel's tasks, with Nitro's compiled Eloquent after the routes.
     */
    public function getOptimizeClearTasks()
    {
        $tasks = [];

        foreach (parent::getOptimizeClearTasks() as $key => $command) {
            $tasks[$key] = $command;

            if ($key === 'routes') {
                $tasks['eloquent'] = 'eloquent:clear';
            }
        }

        return $tasks;
    }

    public function handle()
    {
        parent::handle();

        if (is_file($file = $this->laravel->getCachedFactoriesPath())) {
            @unlink($file);
        }
    }
}
