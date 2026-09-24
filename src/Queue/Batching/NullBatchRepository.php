<?php

namespace Nitro\Queue\Batching;

use Closure;
use Nitro\Queue\QueueManager;
use Nitro\Support\CarbonImmutable;

/**
 * A batch store that keeps batches in memory and settles nothing.
 *
 * For {@see \Nitro\Queue\QueueFake}, where a test wants to assert that code
 * asked for a batch without a batches table existing. The counters are the
 * ones the batch was created with, because no worker is going to move them.
 */
class NullBatchRepository implements BatchRepository
{
    /** @var array<string, Batch> */
    protected array $batches = [];

    public function __construct(
        protected ?QueueManager $queue = null,
    ) {}

    /** @return array<int, Batch> */
    public function get(int $limit = 50, ?string $before = null): array
    {
        return array_slice(array_values($this->batches), 0, $limit);
    }

    public function find(string $batchId): ?Batch
    {
        return $this->batches[$batchId] ?? null;
    }

    public function store(PendingBatch $batch): Batch
    {
        $id = bin2hex(random_bytes(16));

        $jobs = count($batch->jobs());

        return $this->batches[$id] = new Batch(
            queue: $this->queue ?? app(QueueManager::class),
            repository: $this,
            id: $id,
            name: is_string($name = $batch->name()) ? $name : '',
            totalJobs: $jobs,
            pendingJobs: $jobs,
            failedJobs: 0,
            failedJobIds: [],
            options: $batch->options(),
            createdAt: CarbonImmutable::now(),
        );
    }

    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        //
    }

    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts(0, 0);
    }

    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts(0, 0);
    }

    public function markAsFinished(string $batchId): void
    {
        //
    }

    public function cancel(string $batchId): void
    {
        //
    }

    public function delete(string $batchId): void
    {
        unset($this->batches[$batchId]);
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }

    public function rollBack(): void
    {
        //
    }
}
