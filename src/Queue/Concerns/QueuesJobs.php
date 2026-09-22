<?php

namespace Nitro\Queue\Concerns;

use Nitro\Queue\QueuedJob;

/**
 * The parts of a queue driver that do not depend on its storage.
 *
 * Picking a queue by name and remembering which connection you are is
 * the same work whatever is underneath, and bulk() is only an
 * optimisation — a driver that can insert many rows at once overrides
 * it, and one that cannot still behaves correctly by pushing in turn.
 */
trait QueuesJobs
{
    /** The name this connection is configured under. */
    protected string $connectionName = 'default';

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    /** push(), with the queue named first. */
    public function pushOn(string $queue, QueuedJob $job): int|string
    {
        return $this->push($job, $queue);
    }

    /** later(), with the queue named first. */
    public function laterOn(string $queue, int $delay, QueuedJob $job): int|string
    {
        return $this->later($delay, $job, $queue);
    }

    /**
     * Push many jobs onto the same queue.
     *
     * @param array<int, QueuedJob> $jobs
     */
    public function bulk(array $jobs, string $queue = 'default'): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $queue);
        }
    }
}
