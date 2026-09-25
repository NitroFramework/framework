<?php

namespace Nitro\Foundation\Configuration;

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Configuration\ApplicationBuilder as BaseApplicationBuilder;
use Nitro\Console\Kernel as ConsoleKernel;
use Nitro\Foundation\HttpKernel;

/**
 * Laravel's ApplicationBuilder (withRouting, withMiddleware, withExceptions, withProviders,
 * withCommands, withSchedule, ...), unchanged except for the kernels it installs.
 */
class ApplicationBuilder extends BaseApplicationBuilder
{
    public function withKernels()
    {
        $this->app->singleton(HttpKernelContract::class, HttpKernel::class);
        $this->app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);

        return $this;
    }
}
