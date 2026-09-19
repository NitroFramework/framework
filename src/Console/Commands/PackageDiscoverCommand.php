<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
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

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'package:discover' => 'Rebuild the auto-discovered package-provider cache',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments): int
    {
        $manifest = new PackageManifest(
            $this->paths->base('vendor'),
            $this->paths->base(),
            $this->paths->cachedPackages(),
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

        return ExitCode::SUCCESS;
    }
}
