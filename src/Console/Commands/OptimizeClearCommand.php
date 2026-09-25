<?php

namespace Nitro\Console\Commands;

use Illuminate\Foundation\Console\OptimizeClearCommand as BaseOptimizeClearCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's optimize:clear, plus Nitro's compiled container factories.
 */
#[AsCommand(name: 'optimize:clear')]
class OptimizeClearCommand extends BaseOptimizeClearCommand
{
    public function handle()
    {
        parent::handle();

        if (is_file($file = $this->laravel->getCachedFactoriesPath())) {
            @unlink($file);
        }
    }
}
