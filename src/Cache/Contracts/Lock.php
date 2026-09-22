<?php

namespace Nitro\Cache\Contracts;

use Closure;

/**
 * A named claim that only one holder has at a time.
 *
 * The point of an object rather than a callback is that a lock can outlive
 * the call that took it: a queued job holds one for its whole run, a
 * scheduled task for the length of the task. A callback-scoped lock can only
 * express "do this while I have it", which is the easy half.
 *
 * Every lock carries an owner token. Without one, releasing is "delete the
 * key", and a process whose lock has already expired would delete the lock a
 * second process is legitimately holding.
 */
interface Lock
{
    /**
     * Take the lock if it is free.
     *
     * Returns false rather than waiting, which is what makes it usable in a
     * request that must not block.
     */
    public function acquire(): bool;

    /**
     * Take the lock and run the callback, or return false.
     *
     * With no callback this is {@see acquire()}. With one, the lock is
     * released afterwards even if the callback throws — otherwise a failure
     * leaves the lock held until it expires.
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
     * For an operator clearing a lock a dead process left behind — never as
     * part of normal flow, where it would defeat the point of ownership.
     */
    public function forceRelease(): void;

    /** This lock's owner token. */
    public function owner(): string;

    public function isOwnedByCurrentProcess(): bool;
}
