<?php

namespace Nitro\Foundation\Contracts;

/**
 * A long-lived service that holds some per-request state for a worker to clear.
 *
 * Bind a service that is entirely per-request with scoped() instead.
 */
interface ResetsBetweenRequests
{
    /**
     * Drop the state that belongs to the request that just ended.
     *
     * Must not throw: it runs between requests, where there is no request to fail.
     */
    public function resetBetweenRequests(): void;
}
