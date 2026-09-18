<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job threw and will be retried.
 */
class JobExceptionOccurred
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public ?\Throwable $exception = null,
    ) {}
}
