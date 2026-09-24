<?php

namespace Nitro\Broadcasting\Contracts;

/**
 * An event that should reach clients before the request finishes.
 *
 * A plain {@see ShouldBroadcast} is queued: the request returns and a worker
 * does the network call, which is what you want when the driver is a third
 * party that may be slow or down. This one goes out inline instead, for the
 * cases where a queue would be the slower answer — a chat message the sender
 * is watching for, or an application with no worker running.
 */
interface ShouldBroadcastNow extends ShouldBroadcast
{
}
