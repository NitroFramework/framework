<?php

namespace Nitro\Routing\Events;

/**
 * Events the routing layer raises.
 *
 * Declared here rather than in a framework-wide catalogue, so the layer owns
 * its own vocabulary: delete routing and its events go with it, and a package
 * adding a layer names its events without editing anything in core.
 *
 * All three carry a {@see RouteEvent}.
 */
class RoutingEvents
{
    /** Matching is about to begin. Nothing has been found yet. */
    const MATCHED = 'route.matched';

    /** A route was found and is about to be handed to the kernel. */
    const DISPATCHING = 'route.dispatching';

    /** The handler ran and returned, before its result becomes a Response. */
    const DISPATCHED = 'route.dispatched';
}
