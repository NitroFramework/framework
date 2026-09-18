<?php

namespace Nitro\Log\Handlers;

/**
 * Writes to a file named for the day, keeping a fixed number of days.
 *
 * `nitro.log` becomes `nitro-2026-09-18.log`. Older files are pruned once a
 * day rather than on every write, so a busy process is not repeatedly
 * scanning the directory.
 */
class DailyHandler extends StreamHandler
{
    /** The date the current file is named for. */
    protected string $date = '';

    /** The base path, before the date is spliced into it. */
    protected string $basePath;

    /**
     * @param string $path File path; the date is inserted before the extension.
     * @param int    $days How many days to keep; 0 keeps everything.
     */
    public function __construct(
        string $path,
        protected int $days = 14,
    ) {
        $this->basePath = $path;

        parent::__construct($this->pathForToday(), 0);

        // Left empty so the first write rolls over: that is what prunes, and
        // a process that only ever runs on one day would otherwise never do it.
        $this->date = '';
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $rolled = $this->rollToToday();

        parent::write($level, $message, $context);

        // Pruned after the write, so today's file is already on disk and the
        // retention window counts it rather than being off by one.
        if ($rolled) {
            $this->prune();
        }
    }

    /**
     * Point at today's file.
     *
     * @return bool Whether the day had turned over.
     */
    protected function rollToToday(): bool
    {
        $today = date('Y-m-d');

        if ($this->date === $today) {
            return false;
        }

        $this->date = $today;
        $this->path = $this->pathForToday();

        $this->ensureDirectoryExists(dirname($this->path));

        return true;
    }

    /**
     * Splice today's date into the configured filename.
     */
    protected function pathForToday(): string
    {
        $extension = pathinfo($this->basePath, PATHINFO_EXTENSION);
        $withoutExtension = $extension === ''
            ? $this->basePath
            : substr($this->basePath, 0, -(strlen($extension) + 1));

        return $withoutExtension . '-' . date('Y-m-d') . ($extension === '' ? '' : '.' . $extension);
    }

    /**
     * Delete files older than the retention window.
     */
    protected function prune(): void
    {
        if ($this->days <= 0) {
            return;
        }

        $extension = pathinfo($this->basePath, PATHINFO_EXTENSION);
        $pattern = ($extension === ''
            ? $this->basePath
            : substr($this->basePath, 0, -(strlen($extension) + 1))) . '-*';

        $files = glob($pattern . ($extension === '' ? '' : '.' . $extension)) ?: [];

        if (count($files) <= $this->days) {
            return;
        }

        sort($files);

        foreach (array_slice($files, 0, count($files) - $this->days) as $stale) {
            @unlink($stale);
        }
    }
}
