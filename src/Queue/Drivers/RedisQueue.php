<?php

namespace Nitro\Queue\Drivers;

use Nitro\Queue\Concerns\QueuesJobs;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\QueuedJob;

/**
 * Redis-backed queue driver.
 *
 * Four keys per named queue, all keyed by the job's id so a job has exactly one
 * representation no matter which state it is in:
 *
 *   {prefix}{queue}           LIST  ids ready to run, oldest first
 *   {prefix}{queue}:delayed   ZSET  ids not yet eligible, scored by available_at
 *   {prefix}{queue}:reserved  ZSET  ids a worker holds, scored by when the hold lapses
 *   {prefix}{queue}:data      HASH  id => the JSON envelope
 *
 * Moving ids between the sorted sets and the list is done in Lua so a migration
 * is atomic: reading the due ids and removing them in two round-trips would let
 * a second worker read the same ids in between and run every job twice.
 *
 * Visibility timeout — an id whose reserved score has passed is migrated back to
 * the ready list. This is the at-least-once guarantee: a worker that dies mid
 * job leaves its hold to lapse rather than losing the job. Set
 * $visibilityTimeout longer than the slowest job's runtime.
 */
class RedisQueue implements Queue
{
    use QueuesJobs;

    /**
     * Move every member scored at or below ARGV[1] from the sorted set into the
     * ready list, in one atomic step.
     *
     * Pushed in batches because `unpack` is bounded by the Lua stack, and a
     * backlog of delayed jobs can be far larger than that bound.
     */
    private const MIGRATE = <<<'LUA'
        local due = redis.call('zrangebyscore', KEYS[1], '-inf', ARGV[1])
        if #due > 0 then
            redis.call('zremrangebyscore', KEYS[1], '-inf', ARGV[1])
            for i = 1, #due, 100 do
                redis.call('rpush', KEYS[2], unpack(due, i, math.min(i + 99, #due)))
            end
        end
        return #due
    LUA;

    /**
     * @param object $connection        A phpredis client or a connection proxying to one.
     * @param string $prefix            Prepended to every key this driver owns.
     * @param int    $visibilityTimeout Seconds a reservation is honoured.
     */
    public function __construct(
        private object $connection,
        private string $prefix = 'nitro:queue:',
        private int $visibilityTimeout = 90,
    ) {}

    public function push(QueuedJob $job, string $queue = 'default'): int|string
    {
        return $this->store($job, $queue, 0);
    }

    public function later(int $delay, QueuedJob $job, string $queue = 'default'): int|string
    {
        return $this->store($job, $queue, max(0, $delay));
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now = time();

        $this->migrate($this->key($queue, 'delayed'), $this->key($queue), $now);
        $this->migrate($this->key($queue, 'reserved'), $this->key($queue), $now);

        while (($id = $this->connection->lPop($this->key($queue))) !== false && $id !== null) {
            $envelope = $this->connection->hGet($this->key($queue, 'data'), (string) $id);

            // The id outlived its envelope — a delete() that landed between the
            // migration and this pop. Nothing to run; take the next one.
            if (! is_string($envelope)) {
                continue;
            }

            $job = $this->decode($envelope);
            $job->attempts++;
            $job->reservedAt = $now;

            $this->connection->hSet($this->key($queue, 'data'), $job->id, $this->encode($job));
            $this->connection->zAdd($this->key($queue, 'reserved'), $now + $this->visibilityTimeout, $job->id);

            return $job;
        }

        return null;
    }

    public function delete(QueuedJob $job): void
    {
        $queue = $job->queue;

        $this->connection->zRem($this->key($queue, 'reserved'), $job->id);
        $this->connection->zRem($this->key($queue, 'delayed'), $job->id);
        $this->connection->lRem($this->key($queue), (string) $job->id, 0);
        $this->connection->hDel($this->key($queue, 'data'), (string) $job->id);
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $queue = $job->queue;
        $delay = max(0, $delay);

        $job->reservedAt = null;
        $job->availableAt = time() + $delay;

        $this->connection->zRem($this->key($queue, 'reserved'), $job->id);
        $this->connection->hSet($this->key($queue, 'data'), $job->id, $this->encode($job));

        if ($delay > 0) {
            $this->connection->zAdd($this->key($queue, 'delayed'), $job->availableAt, $job->id);

            return;
        }

        $this->connection->rPush($this->key($queue), $job->id);
    }

    /** Ready, delayed and reserved jobs together. */
    public function size(string $queue = 'default'): int
    {
        return (int) $this->connection->lLen($this->key($queue))
            + (int) $this->connection->zCard($this->key($queue, 'delayed'))
            + (int) $this->connection->zCard($this->key($queue, 'reserved'));
    }

    /** Write the envelope and file its id as ready or delayed. */
    private function store(QueuedJob $job, string $queue, int $delay): string
    {
        $job->id = (string) $this->connection->incr($this->prefix . 'id');
        $job->queue = $queue;
        $job->createdAt = time();
        $job->availableAt = time() + $delay;
        $job->reservedAt = null;

        $this->connection->hSet($this->key($queue, 'data'), $job->id, $this->encode($job));

        if ($delay > 0) {
            $this->connection->zAdd($this->key($queue, 'delayed'), $job->availableAt, $job->id);

            return $job->id;
        }

        $this->connection->rPush($this->key($queue), $job->id);

        return $job->id;
    }

    /** Run {@see MIGRATE} against one sorted set. */
    private function migrate(string $from, string $to, int $now): void
    {
        $this->connection->eval(self::MIGRATE, [$from, $to, (string) $now], 2);
    }

    private function key(string $queue, string $suffix = ''): string
    {
        return $this->prefix . $queue . ($suffix === '' ? '' : ':' . $suffix);
    }

    private function encode(QueuedJob $job): string
    {
        return json_encode([
            'id'           => $job->id,
            'queue'        => $job->queue,
            'payload'      => $job->payload,
            'attempts'     => $job->attempts,
            'available_at' => $job->availableAt,
            'reserved_at'  => $job->reservedAt,
            'created_at'   => $job->createdAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function decode(string $envelope): QueuedJob
    {
        $row = json_decode($envelope, true);

        return new QueuedJob(
            id: (string) $row['id'],
            queue: (string) $row['queue'],
            payload: (string) $row['payload'],
            attempts: (int) $row['attempts'],
            availableAt: (int) $row['available_at'],
            reservedAt: isset($row['reserved_at']) ? (int) $row['reserved_at'] : null,
            createdAt: (int) $row['created_at'],
        );
    }
}
