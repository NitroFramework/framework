<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Batching\Batch;

/**
 * A batch has been recorded and its jobs pushed.
 *
 * Raised once, by whoever called dispatch(), before any job has run.
 */
class BatchDispatched
{
    public function __construct(
        public Batch $batch,
    ) {}
}
