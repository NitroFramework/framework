<?php

namespace Nitro\Thrust;

/**
 * Declares which container singletons persist vs. reset between worker requests.
 */
class WorkerMode
{
    // Singletons that persist across requests (boot once, never reset)
    public array $persistentServices = [
        'config',
        'router',
        'routeLoader',
        'view',
        'exceptionManager',
    ];

    // Singletons that reset between every request.
    //
    // 'db' is intentionally NOT here: the Connection (PDO + prepared-statement
    // cache) is safe and beneficial to keep warm across worker iterations —
    // resetting it would force a reconnect every request. Per-request DB state
    // (an accidentally-open transaction) is an app-level bug, not something the
    // worker reset should paper over.
    public array $scopedServices = [
        'request',
        'auth',
        'session',
    ];

    public int $maxRequests = 1000; // restart worker after N requests to prevent memory leaks

    // Forcing a full gc_collect_cycles() after every request adds latency to
    // each warm request for little gain — PHP's GC already runs when the cycle
    // buffer fills. The maxRequests recycle above is the real leak safety net.
    public bool $gcBetweenRequests = false;
}
