<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Batching\Batch;
use Throwable;

/**
 * A batch was cancelled, so its unrun jobs will be skipped.
 *
 * Carries the exception when a failing job cancelled it, and nothing when the
 * cancellation was asked for.
 */
class BatchCanceled
{
    public function __construct(
        public Batch $batch,
        public ?Throwable $exception = null,
    ) {}
}
