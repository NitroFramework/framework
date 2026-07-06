<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Cache\CacheManager;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Data-cache commands (distinct from opcache:clear, which is PHP
 * opcode-cache and lives in OpcacheCommands).
 *
 *   cache:clear [--store=name]   Flush every key in a cache store.
 *   cache:forget <key>           Drop one key (e.g. "students.page.1").
 *   cache:stats                  Show which driver is active.
 *
 * --store accepts any name from config/cache.php → 'stores'. Omitting
 * it targets the default store.
 */
class CacheCommands implements CommandInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly OutputFormatter $output,
        private readonly ConfigRepository $config,
    ) {}

    public function getCommands(): array
    {
        return [
            'cache:clear'  => 'Flush every key in the cache',
            'cache:forget' => 'Drop a single key from the cache',
            'cache:stats'  => 'Show the active cache driver + config',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        match ($command) {
            'cache:clear'  => $this->clear($arguments),
            'cache:forget' => $this->forget($arguments),
            'cache:stats'  => $this->stats(),
            default        => $this->output->error("Unknown cache command: {$command}"),
        };
    }

    // ── cache:clear ───────────────────────────────────────────────────

    private function clear(array $arguments): void
    {
        $store = $this->flagValue($arguments, '--store');
        $repo  = $this->container->get(CacheManager::class)->store($store);

        $label = $store ?? 'default';
        $ok = $repo->flush();

        $ok
            ? $this->output->success("Cleared cache store [{$label}].")
            : $this->output->error("Cache flush returned false for store [{$label}].");
    }

    // ── cache:forget ──────────────────────────────────────────────────

    private function forget(array $arguments): void
    {
        // First non-flag arg is the key. Multiple words allowed if quoted.
        $key = null;
        foreach ($arguments as $a) {
            if (!str_starts_with($a, '--')) { $key = $a; break; }
        }
        if (!$key) {
            $this->output->error("Usage: cache:forget <key> [--store=name]");
            $this->output->writeln("Example: cache:forget students.page.1");
            return;
        }
        $store = $this->flagValue($arguments, '--store');
        $repo  = $this->container->get(CacheManager::class)->store($store);

        $repo->forget($key)
            ? $this->output->success("Forgot key [{$key}].")
            : $this->output->info("Key [{$key}] was not in the cache.");
    }

    // ── cache:stats ───────────────────────────────────────────────────

    private function stats(): void
    {
        $config = $this->config->get('cache');
        $default = $config['default'] ?? 'file';

        $this->output->info("Cache configuration:");
        $this->output->writeln("  Default store: {$default}");
        $this->output->writeln("  Stores defined: " . implode(', ', array_keys($config['stores'] ?? [])));

        $store = $config['stores'][$default] ?? [];
        $this->output->writeln("  Default driver: " . ($store['driver'] ?? '(unknown)'));
        if (isset($store['path']))   $this->output->writeln("  Path:            " . $store['path']);
        if (isset($store['prefix'])) $this->output->writeln("  Prefix:          " . $store['prefix']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $a) {
            if (str_starts_with($a, $flag . '=')) {
                return substr($a, strlen($flag) + 1);
            }
        }
        return null;
    }
}
