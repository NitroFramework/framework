<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\Env;

/**
 * Bootstrapper: loads the .env environment file.
 */
class LoadEnvironment implements BootstrapperInterface
{
    /**
     * Sentinel env var that, when already set in the process environment,
     * signals env vars are coming from the platform (Docker, FrankenPHP worker,
     * cloud env) rather than from .env. Skipping the read in that case removes
     * a file open and parse per request in worker mode.
     */
    private const SKIP_SENTINEL = 'APP_ENV_LOADED';

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
