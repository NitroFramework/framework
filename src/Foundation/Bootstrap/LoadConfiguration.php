<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;

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
        $cachedConfigPath = $this->paths->cache('config.php');

        if (file_exists($cachedConfigPath)) {
            $config = Config::fromArray(require $cachedConfigPath);
        } else {
            $config = $container->make(Config::class);
        }

        // The 'config' alias, the concrete class, and the contract all resolve to
        // the one repository instance — consumers depend on ConfigRepository.
        $container->instance('config', $config);
        $container->instance(Config::class, $config);
        $container->instance(ConfigRepository::class, $config);

        // Hand the Application its config as a typed dependency so it never has
        // to resolve 'config' from the container itself.
        $app->setConfig($config);
    }
}
