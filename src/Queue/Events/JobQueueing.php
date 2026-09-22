<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;

/**
 * A job is about to be pushed onto a queue.
 *
 * The envelope carries the queue name and the encoded payload; the
 * instance is the job as it was written, which is what a listener
 * inspecting properties wants rather than a serialized string.
 */
class JobQueueing
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public ?Job $instance = null,
        public int $delay = 0,
    ) {}
}
