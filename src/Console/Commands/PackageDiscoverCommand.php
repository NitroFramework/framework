<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PackageManifest;
use Nitro\Foundation\PathRegistry;

/**
 * `php nitro package:discover` — (re)build the cached map of auto-discovered
 * package providers (from each installed package's `extra.nitro.providers`).
 *
 * Mirrors Laravel's `package:discover`: wire it into the app's composer
 * `post-autoload-dump` so the cache (storage/cache/packages.php) is rebuilt on
 * every install/update and never goes stale after a `composer require`.
 */
class PackageDiscoverCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {
    }

    public function getCommands(): array
    {
        return [
            'package:discover' => 'Rebuild the auto-discovered package-provider cache',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        $manifest = new PackageManifest(
            $this->paths->base('vendor'),
            $this->paths->base(),
            $this->paths->cache('packages.php'),
        );

        $manifest->build();

        $providers = $manifest->providers();
        $this->output->writeln($this->output->color(
            '  ✓ Discovered ' . count($providers) . ' package provider(s)',
            'green'
        ));
        foreach ($providers as $provider) {
            $this->output->writeln('    ' . $provider);
        }
    }
}
