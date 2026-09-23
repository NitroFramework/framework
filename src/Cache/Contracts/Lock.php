<?php

namespace Nitro\Cache\Contracts;

use Closure;

/**
 * A named claim that only one holder has at a time.
 *
 * An object rather than a callback, because a lock can outlive the call
 * that took it. Every lock carries an owner token, so releasing one is
 * not the same as deleting the key.
 */
interface Lock
{
    /**
     * Take the lock if it is free.
     *
     * Returns false rather than waiting.
     */
    public function acquire(): bool;

    /**
     * Take the lock and run the callback, or return false.
     *
     * With no callback this is {@see acquire()}. With one, the lock is
     * released afterwards even if the callback throws.
     */
    public function get(?Closure $callback = null): mixed;

    /**
     * Wait up to $seconds for the lock, then run the callback.
     *
     * @throws \Nitro\Cache\Exceptions\LockTimeoutException When the wait runs out.
     */
    public function block(int $seconds, ?Closure $callback = null): mixed;

    /** Release it, but only if this process still owns it. */
    public function release(): bool;

    /**
     * Release it whoever holds it.
     *
     * For clearing a lock a dead process left behind, not for normal use.
     */
    public function forceRelease(): void;

    /** This lock's owner token. */
    public function owner(): string;

    public function isOwnedByCurrentProcess(): bool;
}
