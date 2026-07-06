<?php

namespace Nitro\Foundation\Bootstrap;

use Dotenv\Dotenv;
use Nitro\Foundation\Application;

/**
 * Bootstrapper: loads the .env environment file.
 */
class LoadEnvironment implements BootstrapperInterface
{
    /**
     * Sentinel env var that, when already set in the process environment,
     * signals env vars are coming from the platform (Docker, FrankenPHP worker,
     * cloud env) rather than from .env. Skipping Dotenv in that case removes a
     * file open + parse per request in worker mode.
     */
    private const SKIP_SENTINEL = 'APP_ENV_LOADED';

    public function bootstrap(Application $app): void
    {
        if (getenv(self::SKIP_SENTINEL) !== false
            || isset($_ENV[self::SKIP_SENTINEL])
            || isset($_SERVER[self::SKIP_SENTINEL])) {
            return;
        }

        $dotenv = Dotenv::createImmutable($app->paths()->base());
        $dotenv->safeLoad();
    }
}
