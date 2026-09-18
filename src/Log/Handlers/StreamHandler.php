<?php

namespace Nitro\Log\Handlers;

/**
 * Writes to a file on disk, or to a PHP stream such as php://stderr.
 *
 * A stream target is what a container platform expects: it collects the
 * process's own output, and a file written inside a container goes away with
 * the container. A stream has no directory to create and no size to rotate,
 * so both are skipped.
 */
class StreamHandler implements Handler
{
    /** Whether the target is a PHP stream rather than a file on disk. */
    protected bool $isStream;

    /** Directories already confirmed to exist, for the life of the process. */
    protected static array $verifiedDirectories = [];

    /**
     * @param string $path     File path, or a php:// stream URL.
     * @param int    $maxBytes Rotate once the file reaches this size; 0 disables it.
     */
    public function __construct(
        protected string $path,
        protected int $maxBytes = 0,
    ) {
        $this->isStream = str_starts_with($path, 'php://');

        if (! $this->isStream) {
            $this->ensureDirectoryExists(dirname($path));
        }
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $this->rotateIfNeeded();

        file_put_contents($this->path, $this->format($level, $message, $context), FILE_APPEND | LOCK_EX);
    }

    /**
     * Render one line: timestamp, level, message, then context as JSON.
     */
    protected function format(string $level, string $message, array $context): string
    {
        $suffix = $context === [] ? '' : ' ' . json_encode($context);

        return '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ": {$message}{$suffix}\n";
    }

    /**
     * Move the log aside once it reaches the threshold.
     *
     * One generation only: the previous archive is overwritten. The threshold
     * is approximate, since a write may push slightly past it first.
     */
    protected function rotateIfNeeded(): void
    {
        if ($this->isStream || $this->maxBytes <= 0) {
            return;
        }

        // filesize() is stat-cached, so a long-running worker would keep
        // reading the stale size and never rotate.
        clearstatcache(true, $this->path);

        $size = @filesize($this->path);

        if ($size !== false && $size >= $this->maxBytes) {
            @rename($this->path, $this->path . '.1');
        }
    }

    protected function ensureDirectoryExists(string $directory): void
    {
        if (isset(self::$verifiedDirectories[$directory])) {
            return;
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        self::$verifiedDirectories[$directory] = true;
    }

    /**
     * Get the path or stream being written to.
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
