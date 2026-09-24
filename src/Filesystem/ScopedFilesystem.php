<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Concerns\InteractsWithDisk;
use Nitro\Filesystem\Contracts\Filesystem;

/**
 * A disk that is a subtree of another disk.
 *
 *     'tenant' => ['driver' => 'scoped', 'disk' => 's3', 'prefix' => 'tenants/17'],
 *
 * Every path is prefixed on the way in and the prefix stripped on the way out,
 * so code handed one of these cannot address anything outside its subtree and
 * does not know it is in one. That is the point: a per-tenant or per-user disk
 * is passed to code written against a whole disk, and the containment is the
 * disk's property rather than something every call site has to remember.
 *
 * Wraps rather than reimplements, so it inherits whatever the disk underneath
 * can do — including signing, if that disk signs.
 */
class ScopedFilesystem implements Filesystem
{
    use InteractsWithDisk;

    protected string $prefix;

    public function __construct(
        protected Filesystem $disk,
        string $prefix,
    ) {
        $this->prefix = trim(str_replace('\\', '/', $prefix), '/');
    }

    /** The disk this one is a subtree of. */
    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return ['driver' => 'scoped', 'prefix' => $this->prefix] + $this->disk->getConfig();
    }

    // ─── Reading ──────────────────────────────────────────

    public function exists(string $path): bool
    {
        return $this->disk->exists($this->prefixed($path));
    }

    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    public function fileExists(string $path): bool
    {
        return $this->disk->fileExists($this->prefixed($path));
    }

    public function directoryExists(string $directory): bool
    {
        return $this->disk->directoryExists($this->prefixed($directory));
    }

    public function get(string $path): ?string
    {
        return $this->disk->get($this->prefixed($path));
    }

    /** @return resource|null */
    public function readStream(string $path)
    {
        return $this->disk->readStream($this->prefixed($path));
    }

    // ─── Writing ──────────────────────────────────────────

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        return $this->disk->put($this->prefixed($path), $contents, $options);
    }

    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        return $this->disk->writeStream($this->prefixed($path), $resource, $options);
    }

    public function putFile(string $directory, string $sourcePath, array $options = []): string
    {
        return $this->stripped($this->disk->putFile($this->prefixed($directory), $sourcePath, $options));
    }

    public function putFileAs(string $directory, string $sourcePath, string $name, array $options = []): string
    {
        return $this->stripped($this->disk->putFileAs($this->prefixed($directory), $sourcePath, $name, $options));
    }

    public function prepend(string $path, string $data): bool
    {
        return $this->disk->prepend($this->prefixed($path), $data);
    }

    public function append(string $path, string $data): bool
    {
        return $this->disk->append($this->prefixed($path), $data);
    }

    public function delete(string|array $paths): bool
    {
        return $this->disk->delete(array_map(
            fn (string $path): string => $this->prefixed($path),
            (array) $paths,
        ));
    }

    public function copy(string $from, string $to): bool
    {
        return $this->disk->copy($this->prefixed($from), $this->prefixed($to));
    }

    public function move(string $from, string $to): bool
    {
        return $this->disk->move($this->prefixed($from), $this->prefixed($to));
    }

    // ─── Describing ───────────────────────────────────────

    public function size(string $path): ?int
    {
        return $this->disk->size($this->prefixed($path));
    }

    public function lastModified(string $path): ?int
    {
        return $this->disk->lastModified($this->prefixed($path));
    }

    public function mimeType(string $path): ?string
    {
        return $this->disk->mimeType($this->prefixed($path));
    }

    public function checksum(string $path, array $options = []): ?string
    {
        return $this->disk->checksum($this->prefixed($path), $options);
    }

    public function getVisibility(string $path): ?string
    {
        return $this->disk->getVisibility($this->prefixed($path));
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->disk->setVisibility($this->prefixed($path), $visibility);
    }

    // ─── Listing ──────────────────────────────────────────

    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->strip($this->disk->files($this->prefixed($directory ?? ''), $recursive));
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->strip($this->disk->directories($this->prefixed($directory ?? ''), $recursive));
    }

    public function allDirectories(?string $directory = null): array
    {
        return $this->directories($directory, true);
    }

    public function makeDirectory(string $path): bool
    {
        return $this->disk->makeDirectory($this->prefixed($path));
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->disk->deleteDirectory($this->prefixed($directory));
    }

    // ─── Addressing ───────────────────────────────────────

    public function path(string $path = ''): string
    {
        return $this->disk->path($this->prefixed($path));
    }

    public function url(string $path): string
    {
        return $this->disk->url($this->prefixed($path));
    }

    public function providesTemporaryUrls(): bool
    {
        return $this->temporaryUrlBuilder !== null || $this->disk->providesTemporaryUrls();
    }

    public function temporaryUrl(string $path, int $seconds = 3600, array $options = []): string
    {
        if ($this->temporaryUrlBuilder !== null) {
            return ($this->temporaryUrlBuilder)($path, $seconds, $options);
        }

        return $this->disk->temporaryUrl($this->prefixed($path), $seconds, $options);
    }

    // ─── Internals ────────────────────────────────────────

    /**
     * A path as the disk underneath sees it.
     *
     * '..' is dropped rather than resolved: resolving it would let a caller
     * climb out of the subtree, which is the one thing this exists to prevent.
     */
    protected function prefixed(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        $relative = implode('/', $segments);

        if ($this->prefix === '') {
            return $relative;
        }

        return $relative === '' ? $this->prefix : $this->prefix . '/' . $relative;
    }

    /** A path as this disk presents it. */
    protected function stripped(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($this->prefix !== '' && str_starts_with($path, $this->prefix . '/')) {
            return substr($path, strlen($this->prefix) + 1);
        }

        return $path === $this->prefix ? '' : $path;
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    protected function strip(array $paths): array
    {
        return array_values(array_map(fn (string $path): string => $this->stripped($path), $paths));
    }
}
