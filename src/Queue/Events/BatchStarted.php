<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Batching\Batch;

/**
 * The first job of a batch has been processed.
 *
 * Distinct from {@see BatchDispatched}, which says the jobs were pushed. A
 * batch can sit queued for a long time before a worker reaches it, and this is
 * the point where it is actually moving.
 */
class BatchStarted
{
    public function __construct(
        public Batch $batch,
    ) {}
}
