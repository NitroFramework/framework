<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;

/** Contract for early-stage application bootstrappers. */
interface BootstrapperInterface
{
    /** Perform bootstrap tasks on the application. */
    public function bootstrap(Application $app): void;
}
