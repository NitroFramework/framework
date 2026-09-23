<?php

namespace Nitro\Cache\Locks;

use Closure;
use Nitro\Cache\Contracts\Lock;
use Nitro\Support\Str;

/**
 * A lock that is always free.
 *
 * What the null store hands out, so work guarded by one still runs. It
 * guarantees nothing about exclusivity.
 */
class NoLock implements Lock
{
    private string $owner;

    public function __construct(
        private string $name,
        private int $seconds = 0,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? Str::random(40);
    }

    public function acquire(): bool
    {
        return true;
    }

    public function get(?Closure $callback = null): mixed
    {
        return $callback === null ? true : $callback();
    }

    public function block(int $seconds, ?Closure $callback = null): mixed
    {
        return $callback === null ? true : $callback();
    }

    public function release(): bool
    {
        return true;
    }

    public function forceRelease(): void
    {
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function isOwnedByCurrentProcess(): bool
    {
        return true;
    }
}
