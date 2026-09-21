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
        $this->container->singleton(Schedule::class, fn () => new Schedule());
        $this->container->alias('schedule', Schedule::class);

        /*
         * What a due task is allowed to reach for while it runs. Factories, so
         * a schedule of plain callbacks never builds a cache or a queue.
         */
        $this->container->singleton(ScheduleContext::class, function ($container) {
            return new ScheduleContext(
                static fn (): CacheManager => $container->resolve(CacheManager::class),
                static fn (): QueueManager => $container->resolve(QueueManager::class),
                static fn (): CommandManager => $container->resolve(CommandManager::class),
            );
        });
    }
}
