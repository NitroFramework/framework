<?php

namespace Nitro\Queue\Batching;

use Nitro\Queue\QueueManager;
use Nitro\Support\CarbonImmutable;

/**
 * Builds a Batch from a stored row.
 *
 * The queue is handed in here rather than looked up by the repository, so a
 * batch can push the jobs that add() gives it.
 */
class BatchFactory
{
    public function __construct(
        protected QueueManager $queue,
    ) {}

    /** @param object|array<string, mixed> $row */
    public function make(BatchRepository $repository, object|array $row): Batch
    {
        $row = (array) $row;

        $options = $row['options'] ?? [];
        $failedJobIds = $row['failed_job_ids'] ?? [];

        return new Batch(
            $this->queue,
            $repository,
            (string) $row['id'],
            (string) ($row['name'] ?? ''),
            (int) ($row['total_jobs'] ?? 0),
            max(0, (int) ($row['pending_jobs'] ?? 0)),
            (int) ($row['failed_jobs'] ?? 0),
            $this->decodeList($failedJobIds),
            is_string($options) ? (json_decode($options, true) ?: []) : (array) $options,
            $this->time($row['created_at'] ?? null) ?? CarbonImmutable::now(),
            $this->time($row['cancelled_at'] ?? null),
            $this->time($row['finished_at'] ?? null),
        );
    }

    /** @return array<int, string> */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function time(mixed $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $timestamp);
    }
}
