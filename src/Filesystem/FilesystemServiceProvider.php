<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem as FilesystemContract;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the two filesystems, which are not the same thing.
 *
 * 'files' is the local filesystem, addressed by real paths — what a command
 * writing into storage_path() wants. 'filesystem' is the disk manager, whose
 * paths are relative to a configured root and may not be local at all. The
 * File facade reads the first and Storage the second; both used to read the
 * second, which made every File call resolve against a disk root it had no
 * reason to know about.
 *
 * Disks come from config('filesystems') — nothing is hardcoded.
 */
class FilesystemServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /**
     * Every name that should bring this provider with it.
     *
     * The short names are listed alongside the classes because the aliases
     * between them are made in register(), which has not run yet — until it
     * does, asking for one name tells the container nothing about the other.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'files',
            Filesystem::class,
            'filesystem',
            FilesystemManager::class,
            FilesystemContract::class,
        ];
    }

    public function register(): void
    {
        $this->container->singleton('files', fn (): Filesystem => new Filesystem());

        $this->container->alias('files', Filesystem::class);

        $this->container->singleton('filesystem', function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new FilesystemManager((array) $config->get('filesystems', []));
        });

        $this->container->alias('filesystem', FilesystemManager::class);

        $this->container->bind(FilesystemContract::class, function ($container) {
            return $container->resolve('filesystem')->disk();
        }, true);
    }
}
