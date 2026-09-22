<?php

namespace Nitro\Queue\Events;

/**
 * The worker is about to ask a queue for its next job.
 *
 * Nothing has been reserved yet, so there is no job to carry — this
 * names the queue being asked, which is what a listener that has to
 * prepare a connection before the read needs.
 */
class JobPopping
{
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
    ) {}
}
