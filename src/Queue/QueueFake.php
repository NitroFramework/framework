<?php

namespace Nitro\Queue;

use Closure;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Queue\Batching\PendingBatch;
use PHPUnit\Framework\Assert;

/**
 * A queue that records what it was given instead of queueing it.
 *
 *     Queue::fake();
 *
 *     $this->post('/orders', [...]);
 *
 *     Queue::assertPushed(SendInvoice::class);
 *
 * Without this, a test covering code that dispatches has two bad options: run
 * a worker, which makes the test about the worker, or assert on the queue
 * table, which makes it about the driver. Neither says what the test means,
 * which is that this request asked for that job.
 *
 * Nothing reaches a driver while this is in place. The jobs are held, so a
 * test can also look at what was dispatched rather than only that it was.
 */
class QueueFake extends QueueManager
{
    /** @var array<int, array{job: Job, queue: ?string, connection: ?string, delay: int}> */
    protected array $pushed = [];

    /** @var array<int, PendingBatch> */
    protected array $batches = [];

    /** @var array<int, PendingChain> */
    protected array $chains = [];

    /**
     * Job classes that should still be queued for real.
     *
     * For a test that fakes everything but the one job it wants to watch run.
     *
     * @var array<int, class-string>
     */
    protected array $except = [];

    public function __construct(
        ConfigRepository $config,
        protected ?QueueManager $real = null,
    ) {
        // The parent needs its factories; none of them is reached while a
        // push is being recorded rather than made, and a fake that did reach
        // one would be a bug worth hearing about loudly.
        $unreachable = static fn (): never => throw new \LogicException(
            'A faked queue tried to reach a real driver.'
        );

        parent::__construct($config, $unreachable, $unreachable, $unreachable, $unreachable);
    }

    /**
     * Let these jobs through to the real queue.
     *
     * @param class-string|array<int, class-string> $jobs
     */
    public function except(string|array $jobs): static
    {
        foreach ((array) $jobs as $job) {
            $this->except[] = $job;
        }

        return $this;
    }

    // ─── Recording instead of queueing ──────────────────────

    public function dispatch(
        Job $job,
        ?string $queue = null,
        ?string $connection = null,
        int $delay = 0,
    ): int|string {
        if ($this->shouldDispatchForReal($job)) {
            return $this->real->dispatch($job, $queue, $connection, $delay);
        }

        $this->pushed[] = [
            'job' => $job,
            'queue' => $queue ?? $job->queueName(),
            'connection' => $connection,
            'delay' => $delay,
        ];

        return count($this->pushed);
    }

    public function push(Job $job, ?string $queue = null, ?string $connection = null): int|string
    {
        return $this->dispatch($job, $queue, $connection);
    }

    public function later(int $delay, Job $job, ?string $queue = null, ?string $connection = null): int|string
    {
        return $this->dispatch($job, $queue, $connection, max(0, $delay));
    }

    /** Recorded rather than stored, so nothing needs a batches table. */
    public function batch(array|Job $jobs = []): PendingBatch
    {
        $batch = new PendingBatch(
            queue: $this,
            repository: new Batching\NullBatchRepository($this),
            callbacks: new Batching\BatchCallbacks(app(\Nitro\Container\Contracts\ClassResolver::class)),
            jobs: $jobs,
        );

        $this->batches[] = $batch;

        return $batch;
    }

    public function chain(array $jobs): PendingChain
    {
        $chain = new PendingChain($this, $jobs);

        $this->chains[] = $chain;

        return $chain;
    }

    public function reportSkipped(Job $job): void
    {
        //
    }

    public function raise(object $event): void
    {
        //
    }

    protected function shouldDispatchForReal(Job $job): bool
    {
        return $this->real !== null && in_array($job::class, $this->except, true);
    }

    // ─── Looking at what happened ───────────────────────────

    /**
     * The jobs of a class that were pushed, optionally filtered.
     *
     * @param (Closure(Job): bool)|null $filter
     * @return array<int, Job>
     */
    public function pushed(string $job, ?Closure $filter = null): array
    {
        $found = [];

        foreach ($this->pushed as $record) {
            if (! $record['job'] instanceof $job) {
                continue;
            }

            if ($filter === null || $filter($record['job'])) {
                $found[] = $record['job'];
            }
        }

        return $found;
    }

    /** @return array<int, PendingBatch> */
    public function pushedBatches(): array
    {
        return $this->batches;
    }

    /** @return array<int, PendingChain> */
    public function pushedChains(): array
    {
        return $this->chains;
    }

    // ─── Assertions ─────────────────────────────────────────

    /** @param (Closure(Job): bool)|int|null $callback */
    public function assertPushed(string $job, Closure|int|null $callback = null): static
    {
        if (is_int($callback)) {
            return $this->assertPushedTimes($job, $callback);
        }

        Assert::assertNotEmpty(
            $this->pushed($job, $callback),
            "The expected job [{$job}] was not pushed."
        );

        return $this;
    }

    public function assertPushedTimes(string $job, int $times = 1): static
    {
        $count = count($this->pushed($job));

        Assert::assertSame(
            $times,
            $count,
            "The job [{$job}] was pushed {$count} times instead of {$times}."
        );

        return $this;
    }

    /** @param (Closure(Job): bool)|null $callback */
    public function assertNotPushed(string $job, ?Closure $callback = null): static
    {
        Assert::assertEmpty(
            $this->pushed($job, $callback),
            "The unexpected job [{$job}] was pushed."
        );

        return $this;
    }

    public function assertPushedOn(string $queue, string $job, ?Closure $callback = null): static
    {
        return $this->assertPushed($job, function (Job $pushed) use ($job, $queue, $callback): bool {
            foreach ($this->pushed as $record) {
                if ($record['job'] === $pushed && $record['queue'] !== $queue) {
                    return false;
                }
            }

            return $callback === null || $callback($pushed);
        });
    }

    public function assertNothingPushed(): static
    {
        $classes = array_map(static fn (array $r): string => $r['job']::class, $this->pushed);

        Assert::assertEmpty(
            $this->pushed,
            'Jobs were pushed unexpectedly: ' . implode(', ', array_unique($classes)) . '.'
        );

        return $this;
    }

    /**
     * Assert a job was pushed carrying exactly this chain behind it.
     *
     * @param array<int, class-string> $expected
     */
    public function assertPushedWithChain(string $job, array $expected = []): static
    {
        $pushed = $this->pushed($job);

        Assert::assertNotEmpty($pushed, "The expected job [{$job}] was not pushed.");

        $chain = array_map(
            static fn (string $serialized): string => unserialize($serialized)::class,
            $pushed[0]->chained,
        );

        Assert::assertSame($expected, $chain, "The job [{$job}] did not carry the expected chain.");

        return $this;
    }

    public function assertPushedWithoutChain(string $job): static
    {
        return $this->assertPushedWithChain($job, []);
    }

    /** @param (Closure(PendingBatch): bool)|null $callback */
    public function assertBatched(?Closure $callback = null): static
    {
        if ($callback === null) {
            Assert::assertNotEmpty($this->batches, 'No batches were dispatched.');

            return $this;
        }

        foreach ($this->batches as $batch) {
            if ($callback($batch)) {
                Assert::assertTrue(true);

                return $this;
            }
        }

        Assert::fail('No dispatched batch matched.');
    }

    public function assertBatchCount(int $count): static
    {
        Assert::assertCount($count, $this->batches);

        return $this;
    }

    public function assertNothingBatched(): static
    {
        Assert::assertEmpty($this->batches, 'Batches were dispatched unexpectedly.');

        return $this;
    }

    /** @param (Closure(PendingChain): bool)|null $callback */
    public function assertChained(?Closure $callback = null): static
    {
        Assert::assertNotEmpty($this->chains, 'No chains were dispatched.');

        if ($callback !== null) {
            foreach ($this->chains as $chain) {
                if ($callback($chain)) {
                    return $this;
                }
            }

            Assert::fail('No dispatched chain matched.');
        }

        return $this;
    }
}
