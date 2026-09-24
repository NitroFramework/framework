<?php

namespace Nitro\Filesystem;

use Closure;
use InvalidArgumentException;
use Nitro\Filesystem\Contracts\Filesystem;

/**
 * Resolves and caches storage disks from config('filesystems').
 *
 * Calls made on the manager itself proxy to the default disk, so
 * Storage::put(...) works while Storage::disk('public')->put(...) targets a
 * named disk.
 *
 * @mixin Filesystem
 */
class FilesystemManager
{
    /** @var array<string, Filesystem> Resolved disks by name. */
    protected array $disks = [];

    /** @var array<string, Closure> Drivers registered from outside. */
    protected array $customDrivers = [];

    public function __construct(
        protected array $config = []
    ) {}

    public function disk(?string $name = null): Filesystem
    {
        $name ??= $this->getDefaultDriver();

        return $this->disks[$name] ??= $this->resolve($name);
    }

    /** {@see disk()} under the name a caller who thinks in drives uses. */
    public function drive(?string $name = null): Filesystem
    {
        return $this->disk($name);
    }

    /**
     * The disk configured as the application's remote one.
     *
     * For an application that has a local disk and one bucket, which is most
     * of them, this saves naming the bucket at every call site.
     */
    public function cloud(): Filesystem
    {
        $name = $this->getDefaultCloudDriver();

        return $this->disks[$name] ??= $this->resolve($name);
    }

    /**
     * A disk built from configuration given here rather than from the config
     * file.
     *
     * For a one-off root — an export directory named by the request, a
     * tenant's own bucket — that there is no reason to configure globally. A
     * bare string is taken as a local root.
     *
     * @param array<string, mixed>|string $config
     */
    public function build(array|string $config): Filesystem
    {
        return $this->make(is_string($config) ? ['driver' => 'local', 'root' => $config] : $config);
    }

    /**
     * Put a disk in place under a name.
     *
     * What a test uses to swap a real disk for one pointed at a temporary
     * directory, without rewriting the configuration.
     */
    public function set(string $name, Filesystem $disk): static
    {
        $this->disks[$name] = $disk;

        return $this;
    }

    /**
     * Register a driver the framework does not ship.
     *
     * The callback is given the disk's configuration and returns something
     * satisfying the contract. Without this there was no way to add one at
     * all — the driver list was a match statement with no way in.
     *
     * @param Closure(array<string, mixed>, string): Filesystem $callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customDrivers[$driver] = $callback;

        return $this;
    }

    /** Forget a resolved disk, so the next call builds it again. */
    public function forgetDisk(string|array $name): static
    {
        foreach ((array) $name as $each) {
            unset($this->disks[$each]);
        }

        return $this;
    }

    /** Forget every resolved disk. */
    public function purge(?string $name = null): static
    {
        return $name === null ? $this->forgetDisk(array_keys($this->disks)) : $this->forgetDisk($name);
    }

    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? 'local';
    }

    public function getDefaultCloudDriver(): string
    {
        return $this->config['cloud'] ?? 's3';
    }

    protected function resolve(string $name): Filesystem
    {
        $config = $this->config['disks'][$name]
            ?? throw new InvalidArgumentException("Disk [{$name}] is not configured.");

        return $this->make($config, $name);
    }

    /**
     * Build a disk from one configuration array.
     *
     * A driver registered through {@see extend()} wins over a built-in one of
     * the same name, so an application can replace the local driver rather
     * than only add to it. Otherwise the driver is whichever create*Driver
     * method matches, which is also how a subclass adds or replaces one.
     *
     * @param array<string, mixed> $config
     */
    protected function make(array $config, string $name = 'on-demand'): Filesystem
    {
        $driver = (string) ($config['driver'] ?? 'local');

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($config, $name);
        }

        $method = 'create' . str_replace(['-', '_'], '', ucwords($driver, '-_')) . 'Driver';

        if (! method_exists($this, $method)) {
            throw new InvalidArgumentException("Unsupported filesystem driver [{$driver}].");
        }

        return $this->{$method}($config);
    }

    /**
     * A disk on this machine's filesystem.
     *
     * @param array<string, mixed> $config
     */
    public function createLocalDriver(array $config): Filesystem
    {
        return new LocalFilesystem((string) ($config['root'] ?? ''), $config);
    }

    /**
     * A disk on S3-compatible object storage.
     *
     * @param array<string, mixed> $config
     */
    public function createS3Driver(array $config): Filesystem
    {
        return new S3Filesystem($config);
    }

    /**
     * A disk that is a subtree of another.
     *
     * @param array<string, mixed> $config
     */
    public function createScopedDriver(array $config): Filesystem
    {
        if (! isset($config['disk'])) {
            throw new InvalidArgumentException('A scoped disk must name the [disk] it is a subtree of.');
        }

        return new ScopedFilesystem(
            is_string($config['disk']) ? $this->disk($config['disk']) : $this->build($config['disk']),
            (string) ($config['prefix'] ?? ''),
        );
    }

    /** Proxy unknown calls to the default disk. */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->disk()->{$method}(...$arguments);
    }
}
