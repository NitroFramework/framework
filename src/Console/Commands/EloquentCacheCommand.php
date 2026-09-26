<?php

namespace Nitro\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Database\Eloquent\CompiledModels;
use Nitro\Database\Eloquent\ModelCompiler;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan eloquent:cache` (part of `optimize`): compiles Laravel's Model and the boot plan
 * of every model under the configured paths, so models boot without reflecting on every request.
 * Turned off with `nitro.compile.eloquent`.
 */
#[AsCommand(name: 'eloquent:cache')]
class EloquentCacheCommand extends Command
{
    protected $signature = 'eloquent:cache';

    protected $description = 'Compile the Eloquent Model and the boot plans of the application\'s models';

    public function handle(Filesystem $files): int
    {
        $this->callSilent('eloquent:clear');

        $config = $this->laravel['config'];

        if (! $config->get('nitro.compile.eloquent', true)) {
            $this->components->info('Eloquent compilation is turned off (nitro.compile.eloquent).');

            return self::SUCCESS;
        }

        $path = CompiledModels::laravelModelPath();
        $stamp = CompiledModels::stamp($path);

        if ($stamp === null) {
            $this->components->error('Laravel\'s Eloquent Model could not be located through Composer.');

            return self::FAILURE;
        }

        $compiler = new ModelCompiler;
        $model = $compiler->compileModel($files->get($path));

        if ($model === null) {
            $this->components->warn('This Laravel version\'s Model differs from the one Nitro compiles; Laravel\'s Model is used as it is.');

            return self::SUCCESS;
        }

        $plans = ModelCompiler::plans($config->get('nitro.eloquent.paths', [$this->laravel->path()]));

        $files->replace($this->laravel->getCachedEloquentModelPath(), $model);
        $files->replace($this->laravel->getCachedEloquentPath(), ModelCompiler::export($stamp, $plans));

        foreach ($compiler->skipped as $change) {
            $this->components->warn("Kept Laravel's {$change}(): this Laravel version's differs from the one Nitro compiles.");
        }

        $this->components->info(sprintf('Eloquent compiled successfully (%d models).', count($plans)));

        return self::SUCCESS;
    }
}
