<?php

namespace Nitro\Log;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Wires the log manager into the container.
 *
 * Also fills in the paths: a file-backed channel that names none is pointed
 * at the application's own log directory, so a channel can be declared
 * without repeating the path in every application.
 */
class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LogManager::class, function ($container) {
            $paths = $container->resolve(PathRegistry::class);
            $config = (array) $container->resolve(ConfigRepository::class)->get('logging', []);

            return new LogManager($this->normalise($config, $paths));
        });

        $this->container->alias('log', LogManager::class);
    }

    /**
     * Point file-backed channels at the log directory when they name no path.
     *
     * @param array $config The raw `logging` config.
     */
    protected function normalise(array $config, PathRegistry $paths): array
    {
        $default = $paths->storage('logs/nitro.log');

        foreach ($config['channels'] ?? [] as $name => $channel) {
            if (in_array($channel['driver'] ?? '', ['single', 'daily'], true)) {
                $config['channels'][$name]['path'] = $channel['path'] ?: $default;
            }
        }

        return $config;
    }
}
