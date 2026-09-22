<?php

namespace Nitro\Console;

use Nitro\Cache\Repository;

/**
 * Stops a command running while another copy of it is already running.
 *
 * For anything a second copy would corrupt or duplicate — a migration, a queue
 * drain, a nightly export. Held in the cache rather than in a file so that two
 * servers running the same scheduled command still see one lock between them,
 * which is the case a file lock quietly fails to cover.
 *
 * The lock carries a token, and only the holder can release it: a run that
 * overruns its expiry must not be able to delete the lock a later run has
 * since taken.
 */
final class CommandLock
{
    /** How long a lock survives if the process holding it dies. */
    public const DEFAULT_SECONDS = 3600;

    private ?string $token = null;

    public function __construct(
        private Repository $cache,
        private string $signature,
        private int $seconds = self::DEFAULT_SECONDS,
    ) {}

    /** Take the lock, or report that someone else holds it. */
    public function acquire(): bool
    {
        $token = bin2hex(random_bytes(16));

        if (! $this->cache->add($this->key(), $token, $this->seconds)) {
            return false;
        }

        $this->token = $token;

        return true;
    }

    /**
     * Release the lock, but only if this process still holds it.
     *
     * A run slower than the expiry loses the lock to whoever took it next;
     * releasing then would hand a third run a lock the second still thinks is
     * its own.
     */
    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        if ($this->cache->get($this->key()) === $this->token) {
            $this->cache->forget($this->key());
        }

        $this->token = null;
    }

    private function key(): string
    {
        return 'nitro:command-lock:' . sha1($this->signature);
    }
}
