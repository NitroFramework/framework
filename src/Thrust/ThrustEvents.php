<?php

namespace Nitro\Thrust;

/**
 * Event names fired by the Thrust worker loop. Listen for these to hook the
 * long-lived worker lifecycle — e.g. warm a cache on WORKER_STARTING, or flush
 * per-request state you own on REQUEST_HANDLED.
 *
 *   app('events')->listen(ThrustEvents::WORKER_STARTING, fn ($d) => ...);
 */
class ThrustEvents
{
    /** Fired once, after the worker boots and before the request loop. */
    const WORKER_STARTING = 'thrust.worker.starting';

    /** Fired once, when the worker is shutting down (stop signal / max requests). */
    const WORKER_STOPPING = 'thrust.worker.stopping';

    /** Fired at the start of every request handled by the worker. */
    const REQUEST_RECEIVED = 'thrust.request.received';

    /** Fired after every request has been sent. */
    const REQUEST_HANDLED = 'thrust.request.handled';
}
