<?php

namespace Nitro\Queue\Events;

/**
 * A worker is about to look for the next job.
 */
class Looping
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
    ) {}
}
