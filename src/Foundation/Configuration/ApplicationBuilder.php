<?php

namespace Nitro\Foundation\Configuration;

use Illuminate\Foundation\Configuration\ApplicationBuilder as LaravelApplicationBuilder;

/**
 * Laravel's ApplicationBuilder (withRouting, withMiddleware, withExceptions, withProviders,
 * withCommands, withSchedule, ...), unchanged except for the kernels it installs.
 */
class ApplicationBuilder extends LaravelApplicationBuilder
{
    public function withKernels()
    {
        $this->app->singleton(\Illuminate\Contracts\Http\Kernel::class, \Nitro\Foundation\HttpKernel::class);
        $this->app->singleton(\Illuminate\Contracts\Console\Kernel::class, \Nitro\Console\Kernel::class);

        return $this;
    }
}
