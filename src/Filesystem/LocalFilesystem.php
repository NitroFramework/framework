<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem;
use RuntimeException;

/**
 * A disk backed by the local filesystem. All paths are relative to $root and
 * are prevented from escaping it. Visibility maps to file/dir permissions.
 */
class LocalFilesystem implements Filesystem
{
    protected string $root;
    protected string $defaultVisibility;
    protected ?string $url;

    public function __construct(string $root, array $config = [])
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->defaultVisibility = $config['visibility'] ?? 'private';
        $this->url = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    public function get(string $path): ?string
    {
        $full = $this->fullPath($path);

        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory(dirname($full), $options);

        if (is_resource($contents)) {
            $dest = fopen($full, 'wb');
            if ($dest === false) {
                return false;
            }
            stream_copy_to_stream($contents, $dest);
            fclose($dest);
        } elseif (file_put_contents($full, (string) $contents) === false) {
            return false;
        }

        @chmod($full, $this->permissions($options['visibility'] ?? $this->defaultVisibility));

        return true;
    }

    public function putFile(string $directory, string $sourcePath, array $options = []): string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

        return $this->putFileAs($directory, $sourcePath, $name, $options);
    }

    public function putFileAs(string $directory, string $sourcePath, string $name, array $options = []): string
    {
        $target = trim($directory, '/') . '/' . ltrim($name, '/');
        $target = ltrim($target, '/');

        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot read source file: {$sourcePath}");
        }

        $this->put($target, $stream, $options);
        fclose($stream);

        return $target;
    }

    public function prepend(string $path, string $data): bool
    {
        return $this->put($path, $data . ($this->get($path) ?? ''));
    }

    public function append(string $path, string $data): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory(dirname($full));

        return file_put_contents($full, $data, FILE_APPEND) !== false;
    }

    public function delete(string|array $paths): bool
    {
        $ok = true;
        foreach ((array) $paths as $path) {
            $full = $this->fullPath($path);
            if (is_file($full)) {
                $ok = @unlink($full) && $ok;
            }
        }

        return $ok;
    }

    public function copy(string $from, string $to): bool
    {
        $dest = $this->fullPath($to);
        $this->ensureDirectory(dirname($dest));

        return copy($this->fullPath($from), $dest);
    }

    public function move(string $from, string $to): bool
    {
        $dest = $this->fullPath($to);
        $this->ensureDirectory(dirname($dest));

        return rename($this->fullPath($from), $dest);
    }

    public function size(string $path): ?int
    {
        $full = $this->fullPath($path);

        return is_file($full) ? (int) filesize($full) : null;
    }

    public function lastModified(string $path): ?int
    {
        $full = $this->fullPath($path);

        return is_file($full) ? (int) filemtime($full) : null;
    }

    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->scan($directory, $recursive, files: true);
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->scan($directory, $recursive, files: false);
    }

    public function makeDirectory(string $path): bool
    {
        $full = $this->fullPath($path);

        return is_dir($full) || mkdir($full, $this->permissions($this->defaultVisibility, true), true);
    }

    public function deleteDirectory(string $directory): bool
    {
        $full = $this->fullPath($directory);
        if (! is_dir($full)) {
            return false;
        }

        foreach (scandir($full) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $full . '/' . $entry;
            is_dir($child) ? $this->deleteDirectory($directory . '/' . $entry) : @unlink($child);
        }

        return @rmdir($full);
    }

    public function path(string $path = ''): string
    {
        return $this->fullPath($path);
    }

    public function url(string $path): string
    {
        if ($this->url === null) {
            throw new RuntimeException('This disk has no configured URL.');
        }

        return $this->url . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    // ─── internals ────────────────────────────────────────

    /** Resolve a relative path to an absolute one, rejecting escapes above root. */
    protected function fullPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($segments)) {
                    throw new RuntimeException("Path escapes the disk root: {$path}");
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? $this->root : $this->root . '/' . implode('/', $segments);
    }

    protected function ensureDirectory(string $dir, array $options = []): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, $this->permissions($options['visibility'] ?? $this->defaultVisibility, true), true);
        }
    }

    protected function permissions(string $visibility, bool $directory = false): int
    {
        if ($directory) {
            return $visibility === 'public' ? 0755 : 0700;
        }

        return $visibility === 'public' ? 0644 : 0600;
    }

    /** @return array<int, string> Relative paths of files or directories. */
    protected function scan(?string $directory, bool $recursive, bool $files): array
    {
        $base = $directory === null || $directory === '' ? '' : trim($directory, '/');
        $full = $this->fullPath($base);

        if (! is_dir($full)) {
            return [];
        }

        $results = [];
        foreach (scandir($full) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $base === '' ? $entry : $base . '/' . $entry;
            $isDir = is_dir($full . '/' . $entry);

            if ($isDir) {
                if (! $files) {
                    $results[] = $relative;
                }
                if ($recursive) {
                    $results = array_merge($results, $this->scan($relative, true, $files));
                }
            } elseif ($files) {
                $results[] = $relative;
            }
        }

        sort($results);

        return $results;
    }
}
