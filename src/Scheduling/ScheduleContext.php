<?php

namespace Nitro\Scheduling;

use Closure;
use Nitro\Cache\CacheManager;
use Nitro\Console\CommandManager;
use Nitro\Queue\QueueManager;

/**
 * The three services a due task may need while it runs.
 *
 * One task uses at most one of them — a callback needs none, a queued job
 * needs the queue, an overlapping-guarded task needs the cache — so each
 * arrives as a factory and is built only on the branch that reaches it.
 *
 * A typed object rather than three closure parameters threaded through run(),
 * execute(), claimThisMinute() and runCommand(); and a named one rather than a
 * class resolver, so an Event can reach these three and nothing else.
 */
final class ScheduleContext
{
    /**
     * @param Closure(): CacheManager   $cache
     * @param Closure(): QueueManager   $queue
     * @param Closure(): CommandManager $commands
     */
    public function __construct(
        private Closure $cache,
        private Closure $queue,
        private Closure $commands,
    ) {}

    /** The store behind withoutOverlapping() and onOneServer(). */
    public function cache(): CacheManager
    {
        return ($this->cache)();
    }

    /** Where a ->job() task is pushed. */
    public function queue(): QueueManager
    {
        return ($this->queue)();
    }

    /** What runs a ->command() task. */
    public function commands(): CommandManager
    {
        return ($this->commands)();
    }
}
