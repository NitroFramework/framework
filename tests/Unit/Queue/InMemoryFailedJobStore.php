<?php

namespace Tests\Unit\Queue;

use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * Somewhere for a permanently failed job to go, without a database.
 *
 * A worker needs a failed store to construct, so any test that runs one needs
 * this. In its own file because more than one test file runs a worker, and a
 * helper declared inside a test class's file only exists when that file is the
 * one being run.
 */
class InMemoryFailedJobStore implements FailedJobStore
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    public function log(QueuedJob $job, Throwable $exception): string
    {
        $id = (string) (count($this->entries) + 1);

        $this->entries[$id] = [
            'id' => $id,
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => $exception->getMessage(),
            'failed_at' => time(),
        ];

        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $limit = 50): array
    {
        return array_slice(array_values($this->entries), 0, $limit);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->entries[$id] ?? null;
    }

    public function forget(string $id): bool
    {
        if (! isset($this->entries[$id])) {
            return false;
        }

        unset($this->entries[$id]);

        return true;
    }

    public function clear(): int
    {
        $count = count($this->entries);

        $this->entries = [];

        return $count;
    }
}
