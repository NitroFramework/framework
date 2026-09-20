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
    /**
     * Where the next sweep starts reading, kept for the life of the process.
     *
     * A sweep looks at a window of the directory rather than all of it, so the
     * window has to move: a handful of long-lived sessions sitting at the head
     * would otherwise hide everything behind them from every sweep there is.
     *
     * @var array<string, int>
     */
    private static array $cursors = [];

    /**
     * How many directory entries one sweep may walk past before giving up and
     * starting from the front again.
     *
     * A walk costs one directory read per entry — cheap next to a stat, but
     * not free once a directory has been allowed to grow very large. This is a
     * cap on work, not on how many sessions are kept: nothing here decides
     * what is retained, only how far one sweep looks in a single pass.
     */
    private const MAX_ENTRIES_WALKED = 20000;

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
     * Two budgets, because the two costs are nothing alike. Reading a
     * directory entry is cheap; asking the filesystem how old it is costs
     * about twenty-five microseconds, and that is 96% of the sweep. Capping
     * only the deletions leaves the expensive half unbounded: with twenty
     * thousand sessions that are all still live, a sweep stats every one of
     * them, deletes nothing, and takes half a second — and the case where
     * nothing is collectible is the normal one for a busy application.
     *
     * So a sweep stats a bounded window instead, and {@see $cursors} moves the
     * window on so the whole directory is covered across successive sweeps.
     * The backlog drains a little at a time rather than in one stall.
     *
     * glob() is avoided as well — it builds an array of every entry before the
     * first one can be examined.
     *
     * @param int $limit Files to remove at most; 0 for no limit, and no window.
     */
    public function gc(int $max_lifetime, int $limit = 0): int|false
    {
        $cutoff  = time() - $max_lifetime;
        $removed = 0;

        // An explicit "no limit" means a deliberate full sweep — a console
        // command clearing a backlog — so it gets no window either.
        $window = $limit > 0 ? $limit * 2 : PHP_INT_MAX;
        $skip   = $limit > 0 ? (self::$cursors[$this->path] ?? 0) : 0;

        $directory = @opendir($this->path);

        if ($directory === false) {
            return false;
        }

        $seen      = 0;
        $examined  = 0;
        $exhausted = true;

        try {
            while (($entry = readdir($directory)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                // Still walking to where this sweep left off; no stat yet.
                if ($seen++ < $skip) {
                    continue;
                }

                if ($examined >= $window) {
                    $exhausted = false;
                    break;
                }

                $examined++;

                $file  = $this->path . DIRECTORY_SEPARATOR . $entry;
                $mtime = @filemtime($file);

                // is_file() is a second stat, so it only runs for an entry old
                // enough to be a candidate.
                if ($mtime === false || $mtime >= $cutoff || ! is_file($file)) {
                    continue;
                }

                @unlink($file);
                $removed++;

                if ($limit > 0 && $removed >= $limit) {
                    $exhausted = false;
                    break;
                }
            }
        } finally {
            closedir($directory);
        }

        if ($limit > 0) {
            // Reaching the end wraps; so does a skip grown long enough that
            // walking to it would cost more than the window it reaches.
            self::$cursors[$this->path] = ($exhausted || $seen >= self::MAX_ENTRIES_WALKED) ? 0 : $seen;
        }

        return $removed;
    }

    /** Resolve the storage file for an id (ids are validated alnum, safe as filenames). */
    private function pathFor(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $id;
    }
}
