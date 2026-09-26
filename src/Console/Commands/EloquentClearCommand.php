<?php

namespace Nitro\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan eloquent:clear` (part of `optimize:clear`): back to Laravel's Model.
 */
#[AsCommand(name: 'eloquent:clear')]
class EloquentClearCommand extends Command
{
    protected $signature = 'eloquent:clear';

    protected $description = 'Remove the compiled Eloquent Model and boot plans';

    public function handle(Filesystem $files): int
    {
        $files->delete([$this->laravel->getCachedEloquentModelPath(), $this->laravel->getCachedEloquentPath()]);

        $this->components->info('Compiled Eloquent cleared successfully.');

        return self::SUCCESS;
    }
}
