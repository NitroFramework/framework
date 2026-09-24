<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Batching\Batch;

/**
 * Every job in a batch has settled, whether or not they all succeeded.
 *
 * Raised alongside the batch's own finally() callbacks, for a listener that
 * would rather watch than be registered per batch.
 */
class BatchFinished
{
    public function __construct(
        public Batch $batch,
    ) {}
}
