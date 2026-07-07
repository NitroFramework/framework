<?php

namespace Nitro\Scheduling;

use Nitro\Foundation\Providers\ServiceProvider;

/** Binds the Schedule as a shared 'schedule' service for the app to populate. */
class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Schedule::class, fn () => new Schedule());
        $this->container->alias('schedule', Schedule::class);
    }
}
