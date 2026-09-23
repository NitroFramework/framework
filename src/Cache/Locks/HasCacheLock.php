<?php

namespace Nitro\Cache\Locks;

use Nitro\Cache\Contracts\Lock;

/**
 * Locking, for a store whose backend can claim a key atomically.
 *
 * Applying it is a driver's statement that it can; a backend without an
 * atomic add would hand out the same lock twice.
 */
trait HasCacheLock
{
    /**
     * A lock by that name.
     *
     * Nothing is claimed until {@see Lock::acquire()} is called.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new CacheLock($this, $name, $seconds, $owner);
    }

    /**
     * A lock somebody else already holds, by its owner token.
     *
     * The token is what makes a lock releasable from somewhere other
     * than where it was taken.
     */
    public function restoreLock(string $name, string $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }
}
