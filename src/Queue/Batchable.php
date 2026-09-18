<?php

namespace Nitro\Queue;

use Nitro\Queue\Batching\Batch;
use Nitro\Queue\Batching\BatchFactory;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Support\CarbonImmutable;

/**
 * Lets a job know which batch it belongs to.
 *
 *     class ImportRows extends Job
 *     {
 *         use Batchable;
 *
 *         public function handle(): void
 *         {
 *             if ($this->batch()?->cancelled()) {
 *                 return;
 *             }
 *         }
 *     }
 */
trait Batchable
{
    /** Identifier of the batch this job was dispatched with. */
    public ?string $batchId = null;

    /** Set when the job is given a fake batch in a test. */
    protected ?Batch $fakeBatch = null;

    /** The batch as it currently stands, or null when not batched. */
    public function batch(): ?Batch
    {
        if ($this->fakeBatch !== null) {
            return $this->fakeBatch;
        }

        if ($this->batchId === null) {
            return null;
        }

        return app(BatchRepository::class)->find($this->batchId);
    }

    /** Whether this job was dispatched as part of a batch. */
    public function batching(): bool
    {
        return $this->batchId !== null || $this->fakeBatch !== null;
    }

    /** Record which batch this job belongs to. */
    public function withBatchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Give the job a batch that is not stored anywhere.
     *
     * For a test that needs batch() to answer without a database behind it.
     *
     * @param array<int, string>   $failedJobIds
     * @param array<string, mixed> $options
     */
    public function withFakeBatch(
        string $id = '',
        string $name = '',
        int $totalJobs = 0,
        int $pendingJobs = 0,
        int $failedJobs = 0,
        array $failedJobIds = [],
        array $options = [],
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $cancelledAt = null,
        ?CarbonImmutable $finishedAt = null,
    ): static {
        $this->batchId = $id;

        $this->fakeBatch = app(BatchFactory::class)->make(
            app(BatchRepository::class),
            [
                'id' => $id,
                'name' => $name,
                'total_jobs' => $totalJobs,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'failed_job_ids' => $failedJobIds,
                'options' => $options,
                'created_at' => ($createdAt ?? CarbonImmutable::now())->getTimestamp(),
                'cancelled_at' => $cancelledAt?->getTimestamp(),
                'finished_at' => $finishedAt?->getTimestamp(),
            ]
        );

        return $this;
    }
}
