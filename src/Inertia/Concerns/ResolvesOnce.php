<?php

namespace Nitro\Inertia\Concerns;

use DateInterval;
use DateTimeInterface;

/**
 * The bookkeeping behind a prop the client remembers.
 */
trait ResolvesOnce
{
    protected bool $once = false;

    protected bool $refresh = false;

    /** Seconds from now until the client should discard the value. */
    protected ?int $ttl = null;

    protected ?string $key = null;

    /** @return static */
    public function once(bool $value = true, ?string $as = null, DateTimeInterface|DateInterval|int|null $until = null)
    {
        $this->once = $value;

        if ($as !== null) {
            $this->as($as);
        }

        if ($until !== null) {
            $this->until($until);
        }

        return $this;
    }

    public function shouldResolveOnce(): bool
    {
        return $this->once;
    }

    public function shouldBeRefreshed(): bool
    {
        return $this->refresh;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    /** @return static */
    public function as(string $key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Send it regardless of what the client already holds.
     *
     * The escape hatch for the moment the value changes — a permission
     * granted mid-session — where the client's copy is now wrong and waiting
     * for it to expire is not good enough.
     *
     * @return static
     */
    public function fresh(bool $value = true)
    {
        $this->refresh = $value;

        return $this;
    }

    /**
     * Expire the value after a delay, an interval, or at a given time.
     *
     * @return static
     */
    public function until(DateTimeInterface|DateInterval|int $delay)
    {
        $this->ttl = $this->secondsUntil($delay);

        return $this;
    }

    public function expiresAt(): ?int
    {
        // Milliseconds, because the client compares against Date.now().
        return $this->ttl === null ? null : (time() + $this->ttl) * 1000;
    }

    /**
     * A delay in whichever form it was given, as a number of seconds.
     */
    private function secondsUntil(DateTimeInterface|DateInterval|int $delay): int
    {
        if (is_int($delay)) {
            return $delay;
        }

        if ($delay instanceof DateInterval) {
            // Applied to a fixed point rather than read field by field, so a
            // month or a year resolves to its real length.
            $now = new \DateTimeImmutable();

            return max(0, $now->add($delay)->getTimestamp() - $now->getTimestamp());
        }

        return max(0, $delay->getTimestamp() - time());
    }
}
