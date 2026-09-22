<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;

/**
 * A job has been pushed onto a queue.
 *
 * Fired after the driver assigned an identifier, so the envelope's id
 * is set here where it was still null in JobQueueing.
 */
class JobQueued
{
    public function __construct(
        public QueuedJob $job,
        public ?string $connectionName = null,
        public ?Job $instance = null,
        public int $delay = 0,
    ) {}

    /** The identifier the driver gave the queued job. */
    public function id(): int|string|null
    {
        return $this->job->id;
    }
}
