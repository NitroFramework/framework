<?php

namespace Nitro\Queue;

/**
 * Captures dispatch-time overrides (connection, queue, delay) and pushes
 * the job to the configured QueueManager when the builder is committed.
 *
 *   SendWelcomeEmail::dispatch($userId);              // bare — commits on destruct
 *   SendWelcomeEmail::dispatch($id)->delay(60);       // chained — same thing
 *   SendWelcomeEmail::dispatch($id)->onQueue('mail');
 *
 * Commit happens either:
 *   - explicitly via push() (you rarely call this; chained methods do)
 *   - implicitly in __destruct(), when the builder falls out of scope
 *
 * The destruct path is what makes a fire-and-forget call site work
 * without any trailing ->push(). It mirrors Laravel's pattern, so the
 * mental model is portable.
 */
final class PendingDispatch
{
    private ?string $connection = null;
    private ?string $queue = null;
    private int $delay = 0;
    private bool $committed = false;

    public function __construct(private Job $job) {}

    /** Pick a non-default connection (driver) by name from config/queue.php. */
    public function onConnection(?string $name): self
    {
        $this->connection = $name;
        return $this;
    }

    /** Push to a non-default queue (e.g. 'mail', 'reports'). */
    public function onQueue(?string $name): self
    {
        $this->queue = $name;
        return $this;
    }

    /** Defer the job's eligibility by N seconds from now. */
    public function delay(int $seconds): self
    {
        $this->delay = max(0, $seconds);
        return $this;
    }

    /**
     * Commit the dispatch — resolve the QueueManager, pick the right
     * connection + queue, and push. Safe to call multiple times; only
     * the first call has an effect.
     */
    public function push(): int|string|null
    {
        if ($this->committed) {
            return null;
        }
        $this->committed = true;

        $manager = \app(QueueManager::class);
        $queue = $manager->connection($this->connection);
        $queueName = $this->queue ?? $this->job->queueName();

        $envelope = new QueuedJob(
            id: null,
            queue: $queueName,
            payload: QueuedJob::encode($this->job),
            attempts: 0,
            availableAt: time() + $this->delay,
            reservedAt: null,
            createdAt: time(),
        );

        return $this->delay > 0
            ? $queue->later($this->delay, $envelope, $queueName)
            : $queue->push($envelope, $queueName);
    }

    public function __destruct()
    {
        if (!$this->committed) {
            try {
                $this->push();
            } catch (\Throwable $e) {
                // The destructor runs during teardown — throwing here
                // would obscure whatever was actually going on. Surface
                // the failure via error_log and move on; callers that
                // care should call ->push() explicitly to handle errors.
                error_log(
                    '[queue] PendingDispatch::__destruct push failed: '
                    . $e::class . ': ' . $e->getMessage()
                );
            }
        }
    }
}
