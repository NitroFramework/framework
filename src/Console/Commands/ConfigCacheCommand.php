<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;

/**
 * Console commands: compile (config:cache) and clear (config:clear) the config cache.
 */
class ConfigCacheCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output
    ) {}

    public function getCommands(): array
    {
        return [
            'config:cache' => 'Cache all configuration files for improved performance',
            'config:clear' => 'Clear configuration cache',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'config:cache' => $this->cacheConfig(),
            'config:clear' => $this->clearConfig(),
            default        => $this->output->error("Unknown config command: {$command}")
        };
    }

    protected function cacheConfig(): void
    {
        $this->output->info("Caching configuration...");

        try {
            $paths      = $this->paths;
            $configPath = $paths->config();
            $cachePath  = $paths->cache('config.php');

            $config      = new Config($configPath);
            $cacheContent = "<?php\n\nreturn " . var_export($config->all(), true) . ";\n";

            if (file_put_contents($cachePath, $cacheContent) === false) {
                throw new \RuntimeException("Failed to write configuration cache file.");
            }

            $this->output->success("Configuration cached successfully.");
            $this->output->success("Cache file: " . basename($cachePath));
            $this->output->writeln("");
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("Configuration cached successfully!", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } catch (\Exception $e) {
            $this->output->error("Error caching configuration: " . $e->getMessage());
        }
    }

    protected function clearConfig(): void
    {
        $this->output->info("Clearing configuration cache...");

        try {
            $cachePath = $this->paths->cache('config.php');

            if (file_exists($cachePath)) {
                unlink($cachePath);
                $this->output->success("Configuration cache cleared.");
            } else {
                $this->output->warning("No configuration cache found to clear.");
            }

            $this->output->writeln("");
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("Configuration cache cleared!", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } catch (\Exception $e) {
            $this->output->error("Error clearing configuration cache: " . $e->getMessage());
        }
    }
}
