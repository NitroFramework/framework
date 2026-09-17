<?php

namespace Nitro\Foundation\Contracts;

/**
 * A process-lived service that holds some state belonging to one request.
 *
 * Implement it and the worker will clear that state between requests without
 * being told the class exists. The alternative — a list in the worker naming
 * every subsystem that needs clearing — puts the knowledge in the one place
 * least likely to be updated when a subsystem is added, and a missing entry is
 * silent: the service keeps working and serves the previous request's state.
 *
 * For a service that is *entirely* per-request, bind it with scoped() instead.
 * This is for the ones worth keeping — a renderer whose compiled templates are
 * expensive to rebuild, a cache whose warm entries are the point — where only
 * a slice of the state is per-request.
 *
 * Must not throw: it runs between requests, where there is no request to fail.
 */
interface ResetsBetweenRequests
{
    public function resetBetweenRequests(): void;
}
