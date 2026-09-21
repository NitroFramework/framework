<?php

namespace Nitro\Queue\Batching;

use Nitro\Container\Contracts\ClassResolver;
use Throwable;

/**
 * Runs the callbacks a batch was given as its jobs settle.
 *
 * A callback is a class name or a [class, method] pair; the resolver builds
 * it and it receives the batch, plus the exception for catch().
 */
class BatchCallbacks
{
    public function __construct(
        private ClassResolver $resolver,
    ) {}

    /** Run before the batch's jobs are pushed. */
    public function before(Batch $batch): void
    {
        $this->run($batch, 'before', [$batch]);
    }

    /** Run each time a job settles. */
    public function progress(Batch $batch): void
    {
        $this->run($batch, 'progress', [$batch]);
    }

    /** Run catch() callbacks for a failed job. */
    public function failed(Batch $batch, Throwable $exception): void
    {
        $this->run($batch, 'catch', [$batch, $exception]);
    }

    /**
     * Run the callbacks a settled batch has earned.
     *
     * then() only runs when nothing failed; finally() runs either way.
     */
    public function settled(Batch $batch): void
    {
        if (! $batch->finished()) {
            return;
        }

        if (! $batch->hasFailures()) {
            $this->run($batch, 'then', [$batch]);
        }

        $this->run($batch, 'finally', [$batch]);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function run(Batch $batch, string $event, array $arguments): void
    {
        foreach ($batch->options[$event] ?? [] as $callback) {
            try {
                $this->invoke($callback, $arguments);
            } catch (Throwable $exception) {
                error_log("[queue] batch {$event}() callback threw: " . $exception->getMessage());
            }
        }
    }

    /**
     * @param string|array{0: string, 1: string} $callback
     * @param array<int, mixed>                  $arguments
     */
    private function invoke(string|array $callback, array $arguments): void
    {
        [$class, $method] = is_array($callback) ? $callback : [$callback, '__invoke'];

        $this->resolver->resolve($class)->{$method}(...$arguments);
    }
}
