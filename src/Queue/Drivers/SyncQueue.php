<?php

namespace Nitro\Queue\Drivers;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\QueuedJob;

/**
 * Executes pushed jobs immediately, in the same process, on the same
 * request. Not a queue at all — a stand-in that satisfies the contract
 * so dev and test code can pretend to be queueing.
 *
 * Useful when:
 *   - Running tests that need the job's side effect but don't want to
 *     spin a worker.
 *   - Local development without a running `queue:work` process.
 *   - Bootstrapping a new project before the database/redis driver is
 *     wired up.
 *
 * Anything stored is rejected on the way in by running it instead; pop()
 * therefore always returns null. delete() / release() / size() / later()
 * also reduce to trivial behaviour — there's no persistent store to
 * touch. Delay is honored by sleep(), which is rarely what you want in
 * production (hence: don't use this driver in production).
 */
class SyncQueue implements Queue
{
    public function __construct(private ContainerInterface $container) {}

    public function push(QueuedJob $job, string $queue = 'default'): int|string
    {
        $this->execute($job);
        return 0;
    }

    public function later(int $delay, QueuedJob $job, string $queue = 'default'): int|string
    {
        // In sync mode we honor delay literally — surprising, but at
        // least consistent with what every other driver would do.
        // Tests that don't want this should set delay to 0.
        if ($delay > 0) {
            sleep($delay);
        }
        return $this->push($job, $queue);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        // Nothing to pop — push() already ran the job.
        return null;
    }

    public function delete(QueuedJob $job): void
    {
        // No-op: the row never existed.
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        // Released sync jobs are useful for one thing only: tests that
        // simulate failure-then-retry. Re-execute (after optional delay)
        // so the retry path is exercised end-to-end without a worker.
        $job->attempts++;
        if ($delay > 0) {
            sleep($delay);
        }
        $this->execute($job);
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    private function execute(QueuedJob $envelope): void
    {
        ['instance' => $job] = $envelope->decode();

        // Resolve handle()'s dependencies through the container so the
        // job's signature matches what a real worker would inject.
        $reflector = new \ReflectionMethod($job, 'handle');
        $args = [];
        foreach ($reflector->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->container->make($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        $job->handle(...$args);
    }
}
