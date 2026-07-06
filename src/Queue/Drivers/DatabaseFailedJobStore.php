<?php

namespace Nitro\Queue\Drivers;

use Nitro\Database\DB;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\QueuedJob;
use Throwable;

/**
 * Persists exhausted jobs in a `failed_jobs` table so they can be
 * inspected and (optionally) replayed via queue:retry. The original
 * payload is kept verbatim — retry just re-pushes it to the live queue
 * with attempts reset to zero.
 */
class DatabaseFailedJobStore implements FailedJobStore
{
    public function __construct(private string $table = 'failed_jobs') {}

    public function log(QueuedJob $job, Throwable $e): string
    {
        // Pull the class name out of the payload without running unserialize
        // — keeps the failed store usable even when the class no longer exists
        // (e.g. after a deploy that removed it).
        $class = $this->extractClass($job->payload);

        $id = $this->uuid();
        DB::table($this->table)->insert([
            'id'        => $id,
            'queue'     => $job->queue,
            'class'     => $class,
            'attempts'  => $job->attempts,
            'payload'   => $job->payload,
            'exception' => $this->formatException($e),
            'failed_at' => time(),
        ]);
        return $id;
    }

    public function all(int $limit = 50): array
    {
        $rows = DB::table($this->table)
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get()
            ->all();

        return array_map(fn($row) => (array) $row, $rows);
    }

    public function find(string $id): ?array
    {
        $row = DB::table($this->table)->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    public function forget(string $id): bool
    {
        return DB::table($this->table)->where('id', $id)->delete() > 0;
    }

    public function clear(): int
    {
        return DB::table($this->table)->delete();
    }

    private function extractClass(string $payload): string
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? ($decoded['class'] ?? 'unknown') : 'unknown';
    }

    private function formatException(Throwable $e): string
    {
        return $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    }

    /**
     * Tiny UUIDv4 implementation so we don't pull a dependency in just
     * for the failed-job identifier. Good enough for primary keys.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
