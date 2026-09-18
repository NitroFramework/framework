<?php

namespace Nitro\Queue;

use Nitro\Cache\Repository;
use Nitro\Queue\Contracts\ShouldBeUnique;

/**
 * Holds the claim that keeps a unique job from being queued twice.
 *
 * The claim is taken when the job is pushed and given back when it settles,
 * or when it starts for a job that is unique only until processing.
 */
class UniqueLock
{
    public function __construct(
        private Repository $cache,
    ) {}

    /**
     * Take the claim for a job, if it is free.
     *
     * @return bool Whether this dispatch may proceed.
     */
    public function acquire(ShouldBeUnique $job): bool
    {
        return $this->cache->add($this->key($job), 1, $this->seconds($job));
    }

    /** Give the claim back so the job may be queued again. */
    public function release(ShouldBeUnique $job): void
    {
        $this->cache->forget($this->key($job));
    }

    /** Whether the claim is currently held. */
    public function held(ShouldBeUnique $job): bool
    {
        return $this->cache->has($this->key($job));
    }

    /** The cache key standing for one job's claim. */
    public function key(ShouldBeUnique $job): string
    {
        $id = method_exists($job, 'uniqueId') ? (string) $job->uniqueId() : '';

        return 'unique-job:' . $job::class . ($id === '' ? '' : ':' . $id);
    }

    /** How long the claim survives a worker that never releases it. */
    private function seconds(ShouldBeUnique $job): int
    {
        return property_exists($job, 'uniqueFor') ? max(1, (int) $job->uniqueFor) : 3600;
    }
}
