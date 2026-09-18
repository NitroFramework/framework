<?php

namespace Nitro\Queue\Batching;

use Closure;

/**
 * Where batch state is kept while its jobs run.
 *
 * Counters move as jobs settle and every worker reads the same numbers, so an
 * implementation has to be shared across processes.
 */
interface BatchRepository
{
    /**
     * The most recent batches, newest first.
     *
     * @param  int         $limit  Batches to return.
     * @param  string|null $before Return batches created before this id.
     * @return array<int, Batch>
     */
    public function get(int $limit = 50, ?string $before = null): array;

    public function find(string $batchId): ?Batch;

    /** Record a batch that is about to be dispatched. */
    public function store(PendingBatch $batch): Batch;

    public function incrementTotalJobs(string $batchId, int $amount): void;

    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts;

    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts;

    public function markAsFinished(string $batchId): void;

    public function cancel(string $batchId): void;

    public function delete(string $batchId): void;

    /** Run the callback inside a transaction, where the store has them. */
    public function transaction(Closure $callback): mixed;

    /** Roll back the current transaction, where the store has them. */
    public function rollBack(): void;
}
