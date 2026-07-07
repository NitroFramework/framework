<?php

namespace Nitro\Filesystem;

use InvalidArgumentException;
use Nitro\Filesystem\Contracts\Filesystem;

/**
 * Resolves and caches storage disks from config('filesystems'). Calls made on
 * the manager itself proxy to the default disk, so Storage::put(...) works while
 * Storage::disk('public')->put(...) targets a named disk.
 *
 * @mixin Filesystem
 */
class FilesystemManager
{
    /** @var array<string, Filesystem> Resolved disks by name. */
    protected array $disks = [];

    public function __construct(
        protected array $config = []
    ) {}

    public function disk(?string $name = null): Filesystem
    {
        $name ??= $this->getDefaultDriver();

        return $this->disks[$name] ??= $this->resolve($name);
    }

    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? 'local';
    }

    protected function resolve(string $name): Filesystem
    {
        $config = $this->config['disks'][$name]
            ?? throw new InvalidArgumentException("Disk [{$name}] is not configured.");

        $driver = $config['driver'] ?? 'local';

        return match ($driver) {
            'local' => new LocalFilesystem((string) ($config['root'] ?? ''), $config),
            default => throw new InvalidArgumentException("Unsupported filesystem driver [{$driver}]."),
        };
    }

    /** Proxy unknown calls to the default disk. */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->disk()->{$method}(...$arguments);
    }
}
