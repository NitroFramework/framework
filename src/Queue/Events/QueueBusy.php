<?php

namespace Nitro\Queue\Events;

/**
 * A queue has more jobs waiting than the configured threshold.
 */
class QueueBusy
{
    public function __construct(
        public ?string $connectionName = null,
        public string $queue = 'default',
    ) {}
}
