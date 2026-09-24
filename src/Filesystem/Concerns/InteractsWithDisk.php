<?php

namespace Nitro\Filesystem\Concerns;

use Nitro\Filesystem\Contracts\Filesystem;
use Nitro\Http\FileResponse;
use Nitro\Support\Conditionable;
use Nitro\Support\Macroable;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * What every disk answers the same way.
 *
 * Each driver implements the handful of operations that actually differ —
 * reading bytes, listing, permissions — and everything derivable from those
 * lives here, so a new driver is the primitives and nothing else.
 */
trait InteractsWithDisk
{
    use Conditionable;
    use Macroable;

    /**
     * The file's contents, decoded from JSON.
     *
     * Null when the file is absent or does not hold JSON, so a caller can tell
     * "no such config" from "a config of null" only by checking exists().
     *
     * @return array<mixed>|null
     */
    public function json(string $path, int $flags = 0): ?array
    {
        $contents = $this->get($path);

        if ($contents === null) {
            return null;
        }

        $decoded = json_decode($contents, true, 512, $flags);

        return is_array($decoded) ? $decoded : null;
    }

    public function fileMissing(string $path): bool
    {
        return ! $this->fileExists($path);
    }

    public function directoryMissing(string $directory): bool
    {
        return ! $this->directoryExists($directory);
    }

    /**
     * Copy a file onto another disk.
     *
     * Streamed rather than read into memory, so moving a large upload from a
     * local scratch disk to a bucket does not size the request by the file.
     */
    public function copyToDisk(string $from, Filesystem $disk, ?string $to = null, array $options = []): bool
    {
        $stream = $this->readStream($from);

        if ($stream === null) {
            return false;
        }

        try {
            return $disk->writeStream($to ?? $from, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Copy a file onto another disk and remove this one's copy. */
    public function moveToDisk(string $from, Filesystem $disk, ?string $to = null, array $options = []): bool
    {
        return $this->copyToDisk($from, $disk, $to, $options) && $this->delete($from);
    }

    /**
     * A response that makes the browser save the file.
     *
     * The bytes go through the application, which is the point: a private file
     * can be authorised before it is served, where a URL cannot be.
     *
     * @param array<string, string> $headers
     */
    public function download(string $path, ?string $name = null, array $headers = []): FileResponse
    {
        return FileResponse::download($this->localCopy($path), $name ?? basename($path), $headers);
    }

    /**
     * A response that shows the file in the browser.
     *
     * @param array<string, string> $headers
     */
    public function response(string $path, ?string $name = null, array $headers = []): FileResponse
    {
        return FileResponse::inline($this->localCopy($path), $name ?? basename($path), $headers);
    }

    /**
     * A real path a response can read.
     *
     * Local already has one. A remote disk does not, so the object is written
     * to a temporary file and that is served instead.
     */
    protected function localCopy(string $path): string
    {
        $local = $this->path($path);

        if (is_file($local)) {
            return $local;
        }

        $contents = $this->get($path);

        if ($contents === null) {
            throw new RuntimeException("File [{$path}] does not exist on this disk.");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'nitro-disk-');

        file_put_contents($temporary, $contents);

        return $temporary;
    }

    /** @var (\Closure(string, int, array<string, mixed>): string)|null */
    protected $temporaryUrlBuilder = null;

    /** @var (\Closure(string, int, array<string, mixed>): array{url: string, headers: array<string, string>})|null */
    protected $temporaryUploadUrlBuilder = null;

    /** Whether this disk can hand out a URL that expires. */
    public function providesTemporaryUrls(): bool
    {
        return $this->temporaryUrlBuilder !== null;
    }

    public function providesTemporaryUploadUrls(): bool
    {
        return $this->temporaryUploadUrlBuilder !== null;
    }

    /**
     * Build the signed URL yourself.
     *
     * The URL the bucket signs points at the bucket. An application serving
     * those objects through a CDN needs the signature on the CDN's host
     * instead, which only the application knows about.
     *
     * @param \Closure(string, int, array<string, mixed>): string $callback
     */
    public function buildTemporaryUrlsUsing(\Closure $callback): static
    {
        $this->temporaryUrlBuilder = $callback;

        return $this;
    }

    /**
     * @param \Closure(string, int, array<string, mixed>): array{url: string, headers: array<string, string>} $callback
     */
    public function buildTemporaryUploadUrlsUsing(\Closure $callback): static
    {
        $this->temporaryUploadUrlBuilder = $callback;

        return $this;
    }

    /**
     * A URL granting time-limited access to one file.
     *
     * Only a disk that can sign has one. Ask {@see providesTemporaryUrls()}
     * first — a local directory cannot, and saying so is more use than a URL
     * that is not actually limited.
     *
     * @throws RuntimeException
     */
    public function temporaryUrl(string $path, int $seconds = 3600, array $options = []): string
    {
        if ($this->temporaryUrlBuilder !== null) {
            return ($this->temporaryUrlBuilder)($path, $seconds, $options);
        }

        throw new RuntimeException(
            'Disk [' . static::class . '] cannot sign a temporary URL. Check providesTemporaryUrls() first.'
        );
    }

    /**
     * A URL a client can upload one file to directly.
     *
     * @return array{url: string, headers: array<string, string>}
     *
     * @throws RuntimeException
     */
    public function temporaryUploadUrl(string $path, int $seconds = 3600, array $options = []): array
    {
        if ($this->temporaryUploadUrlBuilder !== null) {
            return ($this->temporaryUploadUrlBuilder)($path, $seconds, $options);
        }

        throw new RuntimeException(
            'Disk [' . static::class . '] cannot sign a temporary upload URL.'
        );
    }

    // ─── For a test asserting on what was stored ────────────

    /**
     * @param string|array<int, string> $path
     */
    public function assertExists(string|array $path, ?string $contents = null): static
    {
        foreach ((array) $path as $each) {
            Assert::assertTrue($this->exists($each), "Did not find the expected file [{$each}].");

            if ($contents !== null) {
                Assert::assertSame($contents, $this->get($each), "File [{$each}] does not hold the expected contents.");
            }
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $path
     */
    public function assertMissing(string|array $path): static
    {
        foreach ((array) $path as $each) {
            Assert::assertFalse($this->exists($each), "Found an unexpected file [{$each}].");
        }

        return $this;
    }

    public function assertCount(string $directory, int $count, bool $recursive = false): static
    {
        $found = count($this->files($directory, $recursive)) + count($this->directories($directory, $recursive));

        Assert::assertSame($count, $found, "Directory [{$directory}] holds {$found} entries, not {$count}.");

        return $this;
    }

    public function assertDirectoryEmpty(string $directory): static
    {
        return $this->assertCount($directory, 0);
    }

    public function assertEmpty(): static
    {
        return $this->assertCount('', 0);
    }
}
