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
            $paths     = $this->paths;
            $cachePath = $paths->cache('config.php');

            // Config wants the PathRegistry (not a path string), and MUST ignore
            // any existing cache — otherwise it rebuilds from the stale cache it
            // is about to overwrite. Strip closures/objects var_export can't emit.
            $config = new Config($paths, true);
            $data   = $this->filterSerializable($config->all());

            $cacheContent = "<?php\n\nreturn " . var_export($data, true) . ";\n";

            if (file_put_contents($cachePath, $cacheContent) === false) {
                throw new \RuntimeException("Failed to write configuration cache file.");
            }

            $this->output->success("Configuration cached successfully.");
            $this->output->success("Cache file: " . basename($cachePath));
            $this->output->writeln("");
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("Configuration cached successfully!", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } catch (\Throwable $e) {
            $this->output->error("Error caching configuration: " . $e->getMessage());
        }
    }

    /** Strip values var_export can't emit (closures/objects) before caching. */
    private function filterSerializable(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \Closure || is_object($value)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSerializable($value);
            }
        }
        return $data;
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
