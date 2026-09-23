<?php

namespace Nitro\Queue;

/**
 * The over-the-wire envelope a queue stores and a worker re-hydrates.
 *
 * The producer wraps a Job instance into a QueuedJob (via the queue
 * driver's push() implementation) and the worker reverses that on
 * pop(). Treat it as the queue's row representation, not a public API:
 * user code authors Jobs, never QueuedJobs.
 *
 * Field roles:
 *   - $id           Driver-assigned primary key. Null before push().
 *   - $queue        Named bucket the job lives on ('default' / 'mail' / …).
 *   - $payload      Serialized Job instance + class name, JSON-encoded.
 *                   Stored verbatim so retries reconstruct the same args.
 *   - $attempts     How many times the worker has already started this
 *                   job. Incremented BEFORE handle() runs so a crashed
 *                   handle() still counts.
 *   - $availableAt  Earliest unix timestamp the job can be popped. Used
 *                   for delayed dispatch and retry-with-backoff.
 *   - $reservedAt   When the current worker reserved this row. Null
 *                   means available. Drivers use this both to atomically
 *                   reserve and to detect stale reservations (visibility
 *                   timeout — a row reserved-but-not-deleted for too
 *                   long is presumed orphaned by a dead worker).
 *   - $createdAt    First-push timestamp. Stable across releases.
 *
 * Identity: $id belongs to the driver and changes when a job is retried
 * onto a fresh row, so anything that has to follow one job across its
 * attempts keys off the payload's uuid instead.
 */
final class QueuedJob
{
    public function __construct(
        public ?string $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public int $availableAt,
        public ?int $reservedAt,
        public int $createdAt,
    ) {}

    /**
     * Decode the payload back to its [class, instance] pair without
     * actually running anything. The Worker uses this to find the Job
     * class and hand it to the container for dependency-injected
     * handle() invocation.
     *
     * Throws if the payload is corrupt — the worker treats that as
     * "fail immediately to the failed store" because there's no way
     * to retry a job whose class can't be resolved.
     *
     * @return array{class: class-string<Job>, instance: Job}
     */
    public function decode(): array
    {
        $decoded = json_decode($this->payload, true);
        if (!is_array($decoded) || !isset($decoded['class'], $decoded['data'])) {
            throw new \RuntimeException(
                "QueuedJob payload is malformed: " . substr($this->payload, 0, 200)
            );
        }

        $class = $decoded['class'];
        if (!class_exists($class) || !is_subclass_of($class, Job::class)) {
            throw new \RuntimeException(
                "QueuedJob references unknown or non-Job class: {$class}"
            );
        }

        $instance = unserialize($decoded['data'], ['allowed_classes' => true]);
        if (!$instance instanceof Job) {
            throw new \RuntimeException(
                "QueuedJob payload deserialized to wrong type: " . get_debug_type($instance)
            );
        }

        return ['class' => $class, 'instance' => $instance];
    }

    /**
     * The job's own identifier, stable across releases and retries.
     *
     * Null for a payload written before identifiers existed.
     */
    public function uuid(): ?string
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) && isset($decoded['uuid']) && is_string($decoded['uuid'])
            ? $decoded['uuid']
            : null;
    }

    /** The job's class name, without deserializing its arguments. */
    public function resolveName(): string
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) && isset($decoded['class']) && is_string($decoded['class'])
            ? $decoded['class']
            : 'job';
    }

    /**
     * Build a payload string from a Job instance. Inverse of decode().
     * Used by drivers in push() to produce the stored row body.
     */
    public static function encode(Job $job): string
    {
        return json_encode([
            'uuid'  => bin2hex(random_bytes(16)),
            'class' => $job::class,
            'data'  => serialize($job),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
