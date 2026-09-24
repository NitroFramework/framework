<?php

namespace Nitro\Queue\Events;

/**
 * A paused queue was resumed, so workers on it will take work again.
 */
class QueueResumed
{
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
    ) {}
}
