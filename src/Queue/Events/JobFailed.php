<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\QueuedJob;

/**
 * A job exhausted its attempts.
 */
class JobFailed
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public ?\Throwable $exception = null,
    ) {}
}
