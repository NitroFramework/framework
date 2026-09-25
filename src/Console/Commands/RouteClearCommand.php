<?php

namespace Nitro\Console\Commands;

use Illuminate\Foundation\Console\RouteClearCommand as BaseRouteClearCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's route:clear, plus the container factories compiled alongside the route table.
 */
#[AsCommand(name: 'route:clear')]
class RouteClearCommand extends BaseRouteClearCommand
{
    public function handle()
    {
        $this->files->delete($this->laravel->getCachedFactoriesPath());

        parent::handle();
    }
}
