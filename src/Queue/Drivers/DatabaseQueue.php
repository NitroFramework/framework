<?php

namespace Nitro\Queue\Drivers;

use Nitro\Database\DB;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\QueuedJob;

/**
 * The default production driver. Stores jobs in a SQL table; reservation
 * is atomic via a SELECT ... FOR UPDATE inside a transaction.
 *
 * Why FOR UPDATE and not optimistic locking? FOR UPDATE is one round-trip
 * and is portable across MySQL / MariaDB / Postgres. Two workers polling
 * the same row block; the loser sees the row reserved on its next read.
 * On MySQL 8 / MariaDB 10.6+ this can be tightened to SKIP LOCKED for
 * lower latency, but the plain form is correct everywhere.
 *
 * Visibility timeout — a row whose reserved_at is older than the timeout
 * is treated as available again. This is the at-least-once guarantee:
 * if a worker dies mid-handle, the row eventually becomes pop-able by
 * another worker. Tune $visibilityTimeout to longer than your slowest
 * job's runtime.
 */
class DatabaseQueue implements Queue
{
    public function __construct(
        private string $table = 'jobs',
        private int $visibilityTimeout = 90,
    ) {}

    public function push(QueuedJob $job, string $queue = 'default'): int|string
    {
        return $this->insert($job, $queue, 0);
    }

    public function later(int $delay, QueuedJob $job, string $queue = 'default'): int|string
    {
        return $this->insert($job, $queue, max(0, $delay));
    }

    private function insert(QueuedJob $job, string $queue, int $delay): int
    {
        $now = time();
        $id = DB::table($this->table)->insertGetId([
            'queue'        => $queue,
            'payload'      => $job->payload,
            'attempts'     => $job->attempts,
            'reserved_at'  => null,
            'available_at' => $now + $delay,
            'created_at'   => $now,
        ]);
        $job->id = (string) $id;
        $job->queue = $queue;
        $job->availableAt = $now + $delay;
        $job->createdAt = $now;
        return $id;
    }

    /**
     * Reserve and return the next runnable row on this queue, or null.
     *
     * Done inside a transaction with SELECT ... FOR UPDATE so concurrent
     * workers can't reserve the same row. The UPDATE marks the row
     * reserved and bumps the attempts counter — the counter increments
     * BEFORE handle() runs so a crashed handler still counts toward the
     * tries limit (otherwise a panicking job could loop forever).
     */
    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now = time();
        $cutoff = $now - $this->visibilityTimeout;

        return DB::transaction(function () use ($queue, $now, $cutoff) {
            // Available = (never reserved) OR (reservation stale).
            // available_at <= now ensures we respect delays and backoff.
            $rows = DB::select(
                "SELECT * FROM {$this->table}
                 WHERE queue = ?
                   AND available_at <= ?
                   AND (reserved_at IS NULL OR reserved_at <= ?)
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE",
                [$queue, $now, $cutoff]
            );

            if (empty($rows)) {
                return null;
            }

            $row = (array) $rows[0];

            DB::table($this->table)
                ->where('id', $row['id'])
                ->update([
                    'reserved_at' => $now,
                    'attempts'    => $row['attempts'] + 1,
                ]);

            return new QueuedJob(
                id: (string) $row['id'],
                queue: $row['queue'],
                payload: $row['payload'],
                attempts: $row['attempts'] + 1,
                availableAt: (int) $row['available_at'],
                reservedAt: $now,
                createdAt: (int) $row['created_at'],
            );
        });
    }

    public function delete(QueuedJob $job): void
    {
        if ($job->id === null) {
            return;
        }
        DB::table($this->table)->where('id', $job->id)->delete();
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        if ($job->id === null) {
            return;
        }
        DB::table($this->table)
            ->where('id', $job->id)
            ->update([
                'reserved_at'  => null,
                'available_at' => time() + max(0, $delay),
            ]);
    }

    public function size(string $queue = 'default'): int
    {
        return (int) DB::table($this->table)
            ->where('queue', $queue)
            ->count();
    }
}
