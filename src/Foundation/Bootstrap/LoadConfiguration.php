<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Support\Logger;

/**
 * Load the configuration and hand it to the application.
 */
class LoadConfiguration implements BootstrapperInterface
{
    private PathRegistry $paths;

    public function __construct(PathRegistry $paths)
    {
        $this->paths = $paths;
    }

    /**
     * Load the configuration, from the cache when it is fresh and no test is running.
     */
    public function bootstrap(Application $app): void
    {
        $container = $app->getContainer();
        $cachedConfigPath = $this->paths->cachedConfig();

        if (! Config::runningTests()
            && file_exists($cachedConfigPath)
            && Config::cacheIsFresh($cachedConfigPath, $this->paths->base('.env'))
        ) {
            $config = Config::fromArray(require $cachedConfigPath);
        } else {
            $config = $container->resolve(Config::class);
        }

        $container->instance('config', $config);
        $container->instance(Config::class, $config);
        $container->instance(ConfigRepository::class, $config);

        $app->setConfig($config);

        $this->configureLogger($config);
    }

    /** Point the logger at the configured channel. */
    private function configureLogger(ConfigRepository $config): void
    {
        $channel = (string) $config->get('logging.channel', 'file');

        Logger::setMaxBytes((int) $config->get('logging.max_bytes', 5_242_880));

        Logger::setPath(match ($channel) {
            'stderr' => 'php://stderr',
            'stdout' => 'php://stdout',
            default  => (string) ($config->get('logging.path') ?: $this->paths->storage('logs/nitro.log')),
        });
    }
}
