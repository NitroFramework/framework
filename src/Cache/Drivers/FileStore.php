<?php

namespace Nitro\Cache\Drivers;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Exceptions\CacheReadException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Filesystem cache store — serializes entries to files under the cache path.
 */
class FileStore implements StoreInterface
{
    /**
     * @param string     $directory       The cache directory path.
     * @param string     $prefix          Cache key prefix.
     * @param int        $dirLevels       Number of subdirectory levels for hashing.
     * @param bool|array $allowedClasses  Whitelist for unserialize. Defaults to
     *   `true` (allow all classes) to match Laravel/Symfony conventions —
     *   typical apps cache Eloquent models, paginators, collections, etc. To
     *   harden against object injection on a compromised cache backend, pass
     *   a list like [\stdClass::class, MyDto::class] or `false`.
     */
    public function __construct(
        protected string $directory,
        protected string $prefix = '',
        protected int $dirLevels = 2,
        protected bool|array $allowedClasses = true
    ) {
        // Ensure directory exists
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        return $this->getPayload($key)['data'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $path = $this->path($key);

        $this->ensureDirectoryExists(dirname($path));

        $expiration = time() + $seconds;

        // Pack: 10-digit expiration timestamp + serialized data
        $content = $expiration . serialize($value);

        return file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function putMany(array $values, int $seconds): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (! $this->put($key, $value, $seconds)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        $existing = $this->getPayload($key);

        $new = ((int) ($existing['data'] ?? 0)) + $value;

        // Keep existing TTL or set to 10 years
        $seconds = isset($existing['time'])
            ? max(1, $existing['time'] - time())
            : 315360000;

        return $this->put($key, $new, $seconds) ? $new : false;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 315360000); // 10 years
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): bool
    {
        // @unlink already returns false if the file does not exist; the prior
        // file_exists/unlink combo introduced a TOCTOU race under concurrency.
        return @unlink($this->path($key));
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        if (! is_dir($this->directory)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the full payload (data + expiration) for a key.
     *
     * @param string $key
     * @return array{data: mixed, time: int}|array{}
     */
    protected function getPayload(string $key): array
    {
        $path = $this->path($key);

        // Atomic single-shot read — avoid file_exists/file_get_contents TOCTOU race.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            // Could be a genuine miss (file doesn't exist) OR an I/O failure
            // (permissions, disk error). Distinguish them so a misconfigured
            // cache directory doesn't masquerade as an empty cache.
            if (!file_exists($path)) {
                return [];
            }
            throw new CacheReadException(
                "Cache file [{$path}] exists but could not be read. Check filesystem permissions."
            );
        }

        if (strlen($contents) < 10) {
            // Less than the expiration-header length — file is truncated/corrupt,
            // not a valid (even expired) cache entry.
            throw new CacheReadException(
                "Cache file [{$path}] is corrupt (length " . strlen($contents) . " < 10 bytes expected for header)."
            );
        }

        // First 10 chars = expiration timestamp
        $expiration = (int) substr($contents, 0, 10);

        // Check if expired
        if (time() >= $expiration) {
            $this->forget($key);
            return [];
        }

        $payload = substr($contents, 10);
        $data = @unserialize($payload, ['allowed_classes' => $this->allowedClasses]);

        // unserialize() returning false is only legal when the payload is
        // literally serialize(false). Anything else means corruption.
        if ($data === false && $payload !== serialize(false)) {
            throw new CacheReadException(
                "Cache file [{$path}] could not be unserialized — payload is corrupt."
            );
        }

        return ['data' => $data, 'time' => $expiration];
    }

    /**
     * Get the full path for a given cache key.
     * Uses hash-based subdirectories to avoid too many files in one dir.
     *
     * @param string $key
     * @return string
     */
    protected function path(string $key): string
    {
        $hash = sha1($this->prefix . $key);

        $parts = [];
        for ($i = 0; $i < $this->dirLevels; $i++) {
            $parts[] = substr($hash, $i * 2, 2);
        }

        return $this->directory
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $parts)
            . DIRECTORY_SEPARATOR
            . $hash;
    }

    /**
     * Ensure a directory exists, creating it if necessary.
     *
     * @param string $path
     * @return void
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
