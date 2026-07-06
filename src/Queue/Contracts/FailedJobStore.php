<?php

namespace Nitro\Queue\Contracts;

use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * Storage for jobs that have exhausted their retry budget. Kept separate
 * from the live queue so a poison-pill job never keeps blocking workers
 * and so failures are inspectable after the fact.
 *
 * A failed-job entry holds enough information to either replay the job
 * (queue:retry) or diagnose what went wrong (queue:failed). The original
 * QueuedJob payload is preserved verbatim — retry reconstructs it.
 */
interface FailedJobStore
{
    /**
     * Persist a failed job along with the exception that killed it.
     * Returns the new entry's identifier.
     */
    public function log(QueuedJob $job, Throwable $e): string;

    /**
     * List failed jobs, newest first. Limit defaults to a sensible
     * dashboard page size.
     *
     * @return array<int, array{
     *     id: string,
     *     queue: string,
     *     class: string,
     *     attempts: int,
     *     exception: string,
     *     failed_at: int,
     *     payload: string
     * }>
     */
    public function all(int $limit = 50): array;

    /**
     * Look up a single failed entry by its identifier, or null if it
     * doesn't exist.
     *
     * @return array{
     *     id: string,
     *     queue: string,
     *     class: string,
     *     attempts: int,
     *     exception: string,
     *     failed_at: int,
     *     payload: string
     * }|null
     */
    public function find(string $id): ?array;

    /**
     * Drop a single failed entry. Called after a successful retry, or
     * directly by an operator who wants to dismiss a stale failure.
     */
    public function forget(string $id): bool;

    /**
     * Drop every failed entry. Used by queue:flush.
     */
    public function clear(): int;
}
