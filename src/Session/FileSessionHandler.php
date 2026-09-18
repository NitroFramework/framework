<?php

namespace Nitro\Session;

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

    /**
     * Delete payloads idle past the lifetime, up to $limit of them.
     *
     * The limit is what makes this safe to run automatically. A worker serves
     * requests in a loop, so an unbounded walk of a directory holding a hundred
     * thousand files stalls every request queued behind it; bounded, a sweep
     * costs the same whether the directory holds ten files or a million, and
     * the backlog drains over successive sweeps instead of in one stall.
     *
     * glob() is avoided for the same reason — it builds an array of every entry
     * before the first one can be examined.
     *
     * @param int $limit Files to remove at most; 0 for no limit.
     */
    public function gc(int $max_lifetime, int $limit = 0): int|false
    {
        $cutoff = time() - $max_lifetime;
        $removed = 0;

        $directory = @opendir($this->path);

        if ($directory === false) {
            return false;
        }

        try {
            while (($entry = readdir($directory)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $file = $this->path . DIRECTORY_SEPARATOR . $entry;
                $mtime = @filemtime($file);

                if ($mtime === false || $mtime >= $cutoff || ! is_file($file)) {
                    continue;
                }

                @unlink($file);
                $removed++;

                if ($limit > 0 && $removed >= $limit) {
                    break;
                }
            }
        } finally {
            closedir($directory);
        }

        return $removed;
    }

    /** Resolve the storage file for an id (ids are validated alnum, safe as filenames). */
    private function pathFor(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $id;
    }
}
