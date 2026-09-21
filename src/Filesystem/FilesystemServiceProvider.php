<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem;
use Nitro\Foundation\Contracts\ConfigRepository;
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
        $this->container->singleton('filesystem', function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new FilesystemManager((array) $config->get('filesystems', []));
        });

        $this->container->alias(FilesystemManager::class, 'filesystem');

        $this->container->bind(Filesystem::class, function ($container) {
            return $container->resolve('filesystem')->disk();
        });
    }
}
