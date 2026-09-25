<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\Env;

/**
 * Load the .env file into the environment.
 */
class LoadEnvironment implements BootstrapperInterface
{
    /**
     * Set by the platform when it already provides the environment, so .env is not read.
     */
    private const SKIP_SENTINEL = 'APP_ENV_LOADED';

    /** Load the .env file unless the platform has already set the environment. */
    public function bootstrap(Application $app): void
    {
        if (getenv(self::SKIP_SENTINEL) !== false
            || isset($_ENV[self::SKIP_SENTINEL])
            || isset($_SERVER[self::SKIP_SENTINEL])) {
            return;
        }

        Env::load($app->paths()->base());
    }
}
