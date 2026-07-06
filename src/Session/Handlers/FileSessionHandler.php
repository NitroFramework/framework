<?php

namespace Nitro\Session\Handlers;

use SessionHandlerInterface;

/**
 * Filesystem session handler.
 *
 * Persists each session as a single file named after its id under a directory
 * the framework controls — independent of PHP's native session storage and
 * session_start(). This is the worker-safe default: a long-running worker can
 * read/write sessions per request without PHP's global session machinery.
 */
class FileSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private string $path,
        private int $minutes = 120,
    ) {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->pathFor($id);

        // Shared lock so we never observe a torn write (write() holds LOCK_EX
        // while truncating+writing; an unlocked read could see a partial file
        // and silently drop the whole session).
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }

        $expired  = false;
        $contents = '';

        try {
            @flock($handle, LOCK_SH);

            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime + ($this->minutes * 60) < time()) {
                $expired = true;
            } else {
                $data = stream_get_contents($handle);
                $contents = $data === false ? '' : $data;
            }

            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        // Unlink after the handle is closed (Windows can't remove an open file).
        if ($expired) {
            @unlink($file);
            return '';
        }

        return $contents;
    }

    public function write(string $id, string $data): bool
    {
        return @file_put_contents($this->pathFor($id), $data, LOCK_EX) !== false;
    }

    public function destroy(string $id): bool
    {
        $file = $this->pathFor($id);
        if (is_file($file)) {
            @unlink($file);
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $cutoff = time() - $max_lifetime;
        $removed = 0;
        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    /** Resolve the storage file for an id (ids are validated alnum, safe as filenames). */
    private function pathFor(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $id;
    }
}
