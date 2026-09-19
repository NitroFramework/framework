<?php

namespace Nitro\Scheduling;

use Nitro\Foundation\Providers\ServiceProvider;

/** Binds the Schedule as a shared 'schedule' service for the app to populate. */
class ScheduleServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Schedule::class, 'schedule'];
    }

    public function register(): void
    {
        $this->container->singleton(Schedule::class, fn () => new Schedule());
        $this->container->alias('schedule', Schedule::class);
    }
}
