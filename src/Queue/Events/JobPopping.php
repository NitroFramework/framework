<?php

namespace Nitro\Queue\Events;

/**
 * The worker is about to ask a queue for its next job.
 */
class JobPopping
{
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
    ) {}
}
