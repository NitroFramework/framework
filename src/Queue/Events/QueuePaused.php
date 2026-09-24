<?php

namespace Nitro\Queue\Events;

/**
 * A queue was paused, so workers on it will stop taking new work.
 *
 * Their processes and reservations are kept — a paused worker is idle, not
 * stopped. A null connection or queue means the pause was blanket.
 */
class QueuePaused
{
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
    ) {}
}
