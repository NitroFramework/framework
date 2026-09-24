<?php

namespace Nitro\Queue\Batching;

use JsonSerializable;
use Nitro\Queue\Events\BatchCanceled;
use Nitro\Queue\Events\BatchFinished;
use Nitro\Queue\Events\BatchStarted;
use Nitro\Queue\Job;
use Nitro\Queue\QueueManager;
use Nitro\Support\CarbonImmutable;
use Throwable;

/**
 * A group of jobs dispatched together, and the state they share.
 *
 *     $batch = Queue::batch([new ImportRows(1), new ImportRows(2)])->dispatch();
 *
 *     $batch->progress();    // 0–100
 *     $batch->fresh();       // re-read the counters
 *     $batch->cancel();
 */
class Batch implements JsonSerializable
{
    /** @var array<int, string> Ids of the jobs that failed. */
    public array $failedJobIds;

    /**
     * @param array<int, string>   $failedJobIds
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected QueueManager $queue,
        protected BatchRepository $repository,
        public string $id,
        public string $name,
        public int $totalJobs,
        public int $pendingJobs,
        public int $failedJobs,
        array $failedJobIds,
        public array $options,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $cancelledAt = null,
        public ?CarbonImmutable $finishedAt = null,
    ) {
        $this->failedJobIds = $failedJobIds;
    }

    /** Re-read the batch from storage. */
    public function fresh(): ?static
    {
        return $this->repository->find($this->id);
    }

    /**
     * Add more jobs to a running batch.
     *
     * @param array<int, Job>|Job $jobs
     */
    public function add(array|Job $jobs): static
    {
        $jobs = is_array($jobs) ? $jobs : [$jobs];

        $this->repository->incrementTotalJobs($this->id, count($jobs));

        foreach ($jobs as $job) {
            $job->withBatchId($this->id);

            $this->queue->push(
                $job,
                $this->options['queue'] ?? $job->queueName(),
                $this->options['connection'] ?? $job->connectionName(),
            );
        }

        return $this->fresh() ?? $this;
    }

    /** Jobs that have settled, whether they succeeded or failed. */
    public function processedJobs(): int
    {
        return $this->totalJobs - $this->pendingJobs;
    }

    /** Percentage of the batch that has settled, 0 when it is empty. */
    public function progress(): int
    {
        if ($this->totalJobs === 0) {
            return 0;
        }

        return (int) floor(($this->processedJobs() / $this->totalJobs) * 100);
    }

    /** Note a job succeeded, and finish the batch when it was the last. */
    public function recordSuccessfulJob(string $jobId): void
    {
        $counts = $this->decrementPendingJobs($jobId);

        if ($this->isFirstJobProcessed($counts)) {
            $this->queue->raise(new BatchStarted($this));
        }

        if ($counts->pendingJobs === 0) {
            $this->repository->markAsFinished($this->id);

            $this->queue->raise(new BatchFinished($this));
        }
    }

    /**
     * Whether these counts are the ones left after the batch's first job.
     *
     * One fewer pending than the batch has jobs, which is true exactly once —
     * the point at which the batch stopped waiting and started moving.
     */
    protected function isFirstJobProcessed(UpdatedBatchJobCounts $counts): bool
    {
        return $counts->pendingJobs === $this->totalJobs - 1;
    }

    public function decrementPendingJobs(string $jobId): UpdatedBatchJobCounts
    {
        return $this->repository->decrementPendingJobs($this->id, $jobId);
    }

    /** Note a job failed, cancelling the batch when failures are not allowed. */
    public function recordFailedJob(string $jobId, ?Throwable $exception = null): void
    {
        $counts = $this->incrementFailedJobs($jobId);

        if ($this->isFirstJobProcessed($counts)) {
            $this->queue->raise(new BatchStarted($this));
        }

        if ($counts->failedJobs > 0 && ! $this->allowsFailures()) {
            $this->cancel($exception);
        }

        if ($counts->pendingJobs === 0) {
            $this->repository->markAsFinished($this->id);

            $this->queue->raise(new BatchFinished($this));
        }
    }

    public function incrementFailedJobs(string $jobId): UpdatedBatchJobCounts
    {
        return $this->repository->incrementFailedJobs($this->id, $jobId);
    }

    public function finished(): bool
    {
        return $this->finishedAt !== null;
    }

    public function hasProgressCallbacks(): bool
    {
        return ($this->options['progress'] ?? []) !== [];
    }

    public function hasThenCallbacks(): bool
    {
        return ($this->options['then'] ?? []) !== [];
    }

    public function hasCatchCallbacks(): bool
    {
        return ($this->options['catch'] ?? []) !== [];
    }

    public function hasFinallyCallbacks(): bool
    {
        return ($this->options['finally'] ?? []) !== [];
    }

    public function hasFailureCallbacks(): bool
    {
        return $this->hasCatchCallbacks();
    }

    /** Whether a failed job lets the rest of the batch continue. */
    public function allowsFailures(): bool
    {
        return (bool) ($this->options['allowFailures'] ?? false);
    }

    public function hasFailures(): bool
    {
        return $this->failedJobs > 0;
    }

    /** Stop the jobs that have not run yet. */
    public function cancel(?Throwable $exception = null): void
    {
        $this->repository->cancel($this->id);

        $this->queue->raise(new BatchCanceled($this, $exception));
    }

    public function cancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    /** American spelling, for parity with the framework this mirrors. */
    public function canceled(): bool
    {
        return $this->cancelled();
    }

    public function delete(): void
    {
        $this->repository->delete($this->id);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'totalJobs' => $this->totalJobs,
            'pendingJobs' => $this->pendingJobs,
            'processedJobs' => $this->processedJobs(),
            'progress' => $this->progress(),
            'failedJobs' => $this->failedJobs,
            'options' => $this->options,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'cancelledAt' => $this->cancelledAt?->format('Y-m-d H:i:s'),
            'finishedAt' => $this->finishedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
