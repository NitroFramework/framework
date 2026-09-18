<?php

namespace Nitro\Queue\Batching;

use Closure;
use DateTimeInterface;
use Nitro\Database\DB;

/**
 * Keeps batch state in a `job_batches` table.
 *
 * Counters move with a single UPDATE carrying the arithmetic rather than a
 * read followed by a write, so two workers settling jobs at the same moment
 * cannot both read the same pending count and write the same decrement.
 */
class DatabaseBatchRepository implements PrunableBatchRepository
{
    public function __construct(
        protected BatchFactory $factory,
        protected string $table = 'job_batches',
    ) {}

    public function get(int $limit = 50, ?string $before = null): array
    {
        $query = DB::table($this->table)->orderByDesc('created_at')->limit($limit);

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $batches = [];

        foreach ($query->get() as $row) {
            $batches[] = $this->factory->make($this, $row);
        }

        return $batches;
    }

    public function find(string $batchId): ?Batch
    {
        $row = DB::table($this->table)->where('id', $batchId)->first();

        return $row === null ? null : $this->factory->make($this, $row);
    }

    public function store(PendingBatch $batch): Batch
    {
        $id = $this->newId();
        $now = time();
        $total = count($batch->jobs());

        DB::table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name(),
            'total_jobs' => $total,
            'pending_jobs' => $total,
            'failed_jobs' => 0,
            'failed_job_ids' => json_encode([]),
            'options' => json_encode($batch->options()),
            'created_at' => $now,
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        return $this->find($id) ?? $this->factory->make($this, [
            'id' => $id,
            'name' => $batch->name(),
            'total_jobs' => $total,
            'pending_jobs' => $total,
            'failed_jobs' => 0,
            'options' => json_encode($batch->options()),
            'created_at' => $now,
        ]);
    }

    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        DB::table($this->table)
            ->where('id', $batchId)
            ->incrementEach(['total_jobs' => $amount, 'pending_jobs' => $amount]);

        DB::table($this->table)
            ->where('id', $batchId)
            ->update(['finished_at' => null]);
    }

    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        DB::table($this->table)
            ->where('id', $batchId)
            ->where('pending_jobs', '>', 0)
            ->decrement('pending_jobs');

        return $this->currentCounts($batchId);
    }

    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $row = DB::table($this->table)->where('id', $batchId)->first();

        $failedIds = $row === null ? [] : (json_decode((string) $row->failed_job_ids, true) ?: []);
        $failedIds[] = $jobId;

        DB::table($this->table)
            ->where('id', $batchId)
            ->update(['failed_job_ids' => json_encode(array_values(array_unique($failedIds)))]);

        DB::table($this->table)
            ->where('id', $batchId)
            ->where('pending_jobs', '>', 0)
            ->incrementEach(['pending_jobs' => -1, 'failed_jobs' => 1]);

        return $this->currentCounts($batchId);
    }

    public function markAsFinished(string $batchId): void
    {
        DB::table($this->table)
            ->where('id', $batchId)
            ->whereNull('finished_at')
            ->update(['finished_at' => time()]);
    }

    public function cancel(string $batchId): void
    {
        DB::table($this->table)
            ->where('id', $batchId)
            ->update(['cancelled_at' => time()]);
    }

    public function delete(string $batchId): void
    {
        DB::table($this->table)->where('id', $batchId)->delete();
    }

    public function prune(DateTimeInterface $before): int
    {
        return DB::table($this->table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before->getTimestamp())
            ->delete();
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function rollBack(): void
    {
        DB::rollBack();
    }

    private function currentCounts(string $batchId): UpdatedBatchJobCounts
    {
        $row = DB::table($this->table)->where('id', $batchId)->first();

        if ($row === null) {
            return new UpdatedBatchJobCounts();
        }

        return new UpdatedBatchJobCounts(
            max(0, (int) $row->pending_jobs),
            (int) $row->failed_jobs
        );
    }

    private function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
