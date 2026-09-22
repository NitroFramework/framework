<?php

namespace Nitro\Cache\Locks;

use Nitro\Cache\Contracts\Lock;

/**
 * Locking, for a store whose backend can claim a key atomically.
 *
 * A trait rather than a method on the contract, because not every backend can
 * honour it: a null store accepts a lock and never holds one, and a store
 * without an atomic add would hand out the same lock twice. Applying this is
 * a driver's statement that it can.
 */
trait HasCacheLock
{
    /**
     * A lock by that name.
     *
     * Nothing is claimed until {@see Lock::acquire()} is called — this only
     * describes the claim, which is what lets a caller decide between trying
     * once and waiting.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new CacheLock($this, $name, $seconds, $owner);
    }

    /**
     * A lock somebody else already holds, by its owner token.
     *
     * The token is what makes a lock releasable from somewhere other than
     * where it was taken: a queued job records it, and whatever finishes the
     * work later can release the lock it was actually holding rather than
     * whatever happens to carry that name by then.
     */
    public function restoreLock(string $name, string $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }
}
