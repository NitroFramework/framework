<?php

namespace Nitro\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Nitro\Support\Opcache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan nitro:clear`: undo `nitro:optimize`, back to a development setup.
 */
#[AsCommand(name: 'nitro:clear')]
class NitroClearCommand extends Command
{
    protected $signature = 'nitro:clear {--no-composer : Keep the current Composer autoloader}';

    protected $description = 'Remove every cache written by nitro:optimize and restore the development autoloader';

    public function handle(): int
    {
        $this->components->task('Framework caches (optimize:clear)', fn () => $this->callSilently('optimize:clear') === 0);

        $this->components->task('Warm views manifest', function () {
            @unlink($this->laravel->getCachedViewsManifestPath());

            return true;
        });

        if (! $this->option('no-composer')) {
            $this->components->task('Composer autoloader (development)', fn () => (new Composer($this->laravel['files'], $this->laravel->basePath()))
                ->dumpAutoloads(['--no-scripts']) === 0);
        }

        $this->components->task('Opcache reset (this CLI process)', fn () => Opcache::available() ? Opcache::reset() : true);

        $this->components->info('Cleared. Reload php-fpm (or restart `php artisan serve`) so the web server drops cached bytecode.');

        return self::SUCCESS;
    }
}
