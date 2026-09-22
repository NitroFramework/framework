<?php

namespace Nitro\Inertia\Contracts;

use DateInterval;
use DateTimeInterface;

/**
 * A prop the client keeps, so the server stops sending it.
 *
 * Something true for a whole session — a permissions map, a currency list —
 * costs nothing to compute but is dead weight on every page payload. The
 * client reports which of these it already holds, and those are left out.
 */
interface Onceable
{
    /** @return static */
    public function once(bool $value = true);

    public function shouldResolveOnce(): bool;

    /** Send it again even though the client says it has it. */
    public function shouldBeRefreshed(): bool;

    /**
     * The name the client remembers this by.
     *
     * Defaults to the prop's path. Worth setting when the same value appears
     * under different names, so the two share one entry instead of being
     * fetched twice.
     */
    public function getKey(): ?string;

    /** @return static */
    public function as(string $key);

    /** @return static */
    public function until(DateTimeInterface|DateInterval|int $delay);

    /** When the client should discard it, in milliseconds since the epoch. */
    public function expiresAt(): ?int;
}
