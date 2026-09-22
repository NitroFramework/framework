<?php

namespace Nitro\Cache\Locks;

use Closure;
use Nitro\Cache\Contracts\Lock as LockContract;
use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Exceptions\LockTimeoutException;
use Nitro\Support\Str;

/**
 * A lock held as a cache entry.
 *
 * Works on any store that can add a key only when it is absent, which is what
 * makes the claim atomic — two processes calling add() at the same moment,
 * one of them gets false.
 *
 * The entry's value is the owner token, not a flag. That is what lets
 * release() tell "my lock" from "a lock with the same name that somebody else
 * took after mine expired", which a delete-by-name cannot.
 */
class CacheLock implements LockContract
{
    private string $owner;

    /** How long to wait between attempts while blocking. */
    private int $sleepMilliseconds = 250;

    public function __construct(
        private StoreInterface $store,
        private string $name,
        private int $seconds,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? Str::random(40);
    }

    public function acquire(): bool
    {
        /*
         * add() is the whole mechanism: it writes only when the key is absent
         * and reports whether it did. Doing this as get()-then-put() would
         * leave a window in which two processes both see it free.
         */
        if ($this->seconds > 0) {
            return $this->store->add($this->name, $this->owner, $this->seconds);
        }

        if ($this->store->get($this->name) !== null) {
            return false;
        }

        return $this->store->forever($this->name, $this->owner);
    }

    public function get(?Closure $callback = null): mixed
    {
        $acquired = $this->acquire();

        if ($acquired && $callback !== null) {
            try {
                return $callback();
            } finally {
                // Released even when the callback throws: otherwise a failure
                // holds the lock until it expires, and the work it guards
                // stops running for that long.
                $this->release();
            }
        }

        return $acquired;
    }

    public function block(int $seconds, ?Closure $callback = null): mixed
    {
        $deadline = microtime(true) + $seconds;

        while (! $this->acquire()) {
            if (microtime(true) + ($this->sleepMilliseconds / 1000) > $deadline) {
                throw new LockTimeoutException(
                    "Timed out waiting {$seconds}s for lock [{$this->name}]."
                );
            }

            usleep($this->sleepMilliseconds * 1000);
        }

        if ($callback !== null) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function release(): bool
    {
        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        return $this->store->forget($this->name);
    }

    public function forceRelease(): void
    {
        $this->store->forget($this->name);
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function isOwnedByCurrentProcess(): bool
    {
        return $this->currentOwner() === $this->owner;
    }

    /** How long to wait between attempts while blocking. */
    public function betweenBlockedAttemptsSleepFor(int $milliseconds): static
    {
        $this->sleepMilliseconds = $milliseconds;

        return $this;
    }

    /** The token written into the store, or null when nothing holds the lock. */
    private function currentOwner(): mixed
    {
        return $this->store->get($this->name);
    }
}
