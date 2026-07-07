<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the FilesystemManager as 'filesystem'. The default disk is also bound
 * to the Filesystem contract so it can be injected. Disks come from
 * config('filesystems') — nothing is hardcoded.
 */
class FilesystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('filesystem', function () {
            return new FilesystemManager((array) config('filesystems', []));
        });

        $this->container->alias(FilesystemManager::class, 'filesystem');

        $this->container->bind(Filesystem::class, function ($c) {
            return $c->make('filesystem')->disk();
        });
    }
}
