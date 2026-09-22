<?php

namespace Nitro\Scheduling;

use Nitro\Cache\CacheManager;
use Nitro\Console\CommandManager;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Queue\QueueManager;

/** Binds the Schedule as a shared 'schedule' service for the app to populate. */
class ScheduleServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Schedule::class, 'schedule', ScheduleContext::class];
    }

    public function register(): void
    {
        $this->container->singleton(Schedule::class);
        $this->container->alias(Schedule::class, 'schedule');

        /*
         * What a due task is allowed to reach for while it runs. Factories, so
         * a schedule of plain callbacks never builds a cache or a queue.
         */
        $this->container->singleton(ScheduleContext::class, function ($container) {
            return new ScheduleContext(
                cache: static fn (): CacheManager => $container->resolve(CacheManager::class),
                queue: static fn (): QueueManager => $container->resolve(QueueManager::class),
                commands: static fn (): CommandManager => $container->resolve(CommandManager::class),
            );
        });
    }
}
