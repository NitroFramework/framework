<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Support\Logger;

/**
 * Bootstrapper: loads configuration and injects it into the Application.
 */
class LoadConfiguration implements BootstrapperInterface
{
    private PathRegistry $paths;

    public function __construct(PathRegistry $paths)
    {
        $this->paths = $paths;
    }

    public function bootstrap(Application $app): void
    {
        $container = $app->getContainer();
        $cachedConfigPath = $this->paths->cachedConfig();

        // Use the compiled cache only when it's fresh relative to .env, and
        // never under a test runner — see Config::runningTests(). The same
        // guard is applied in Config::__construct.
        if (! Config::runningTests()
            && file_exists($cachedConfigPath)
            && Config::cacheIsFresh($cachedConfigPath, $this->paths->base('.env'))
        ) {
            $config = Config::fromArray(require $cachedConfigPath);
        } else {
            $config = $container->resolve(Config::class);
        }

        // The 'config' alias, the concrete class, and the contract all resolve to
        // the one repository instance — consumers depend on ConfigRepository.
        $container->instance('config', $config);
        $container->instance(Config::class, $config);
        $container->instance(ConfigRepository::class, $config);

        // Hand the Application its config as a typed dependency so it never has
        // to resolve 'config' from the container itself.
        $app->setConfig($config);

        $this->configureLogger($config);
    }

    /**
     * Re-point the logger now that configuration is readable.
     *
     * The Application sets a file path while registering its base bindings, so
     * anything that fails before this point is still recorded; this is the
     * first moment the application's own choice of channel is known.
     */
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
