<?php

namespace Nitro\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `php artisan nitro:optimize`: every production cache in one pass, after Nitro's optimize, then
 * a checklist of what still costs time on each request.
 *
 *   1. package manifest          6. views: compile, parse-check (view:warm)
 *   2. config                    7. optimized Composer autoloader (classmap)
 *   3. events                    8. package optimize commands
 *   4. routes + container        9. checklist
 *   5. Eloquent (eloquent:cache)
 *
 * Only orchestrates existing commands; `php artisan nitro:clear` reverts everything.
 */
#[AsCommand(name: 'nitro:optimize')]
class NitroOptimizeCommand extends Command
{
    protected $signature = 'nitro:optimize
        {--authoritative : Classmap-authoritative autoloader (no filesystem fallback for unknown classes)}
        {--no-composer : Skip regenerating the Composer autoloader}';

    protected $description = 'Cache everything for production (config, events, routes, container, Eloquent, views, autoloader) and check what still costs time';

    /** @var list<string> */
    private array $errors = [];

    public function handle(): int
    {
        $started = hrtime(true);

        $this->newLine();
        $this->components->info('Nitro optimization');

        $steps = [
            'Package manifest' => fn () => $this->artisan('package:discover'),
            'Configuration' => fn () => $this->artisan('config:cache'),
            'Events' => fn () => $this->artisan('event:cache'),
            'Routes + container factories' => fn () => $this->artisan('route:cache'),
            'Eloquent' => fn () => $this->artisan('eloquent:cache'),
            'Views (compile, parse-check)' => fn () => $this->artisan('view:warm'),
            'Composer autoloader' => fn () => $this->composer(),
            'Package optimize commands' => fn () => $this->packageOptimizers(),
        ];

        foreach ($steps as $label => $step) {
            $this->components->task($label, function () use ($step) {
                try {
                    return $step();
                } catch (Throwable $e) {
                    $this->errors[] = "{$e->getMessage()}";

                    return false;
                }
            });
        }

        foreach ($this->errors as $error) {
            $this->components->error($error);
        }

        $this->components->info(sprintf('Done in %.0f ms.', (hrtime(true) - $started) / 1e6));

        $this->checklist();

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function artisan(string $command, array $arguments = []): bool
    {
        if ($this->callSilently($command, $arguments) !== 0) {
            $this->errors[] = "`php artisan {$command}` failed; run it directly to see why.";

            return false;
        }

        return true;
    }

    /**
     * Optimize commands registered by packages (ServiceProvider::optimizes()), as `optimize` runs them.
     */
    private function packageOptimizers(): bool
    {
        $ok = true;

        foreach (ServiceProvider::$optimizeCommands as $command) {
            $ok = $this->artisan($command) && $ok;
        }

        return $ok;
    }

    private function composer(): bool
    {
        if ($this->option('no-composer')) {
            return true;
        }

        $composer = new Composer($this->laravel['files'], $this->laravel->basePath());
        $flags = ['--optimize', '--no-scripts'];

        if ($this->option('authoritative')) {
            $flags[] = '--classmap-authoritative';
        }

        return $composer->dumpAutoloads($flags) === 0;
    }

    /**
     * What is still paid on every request, read from the app's own configuration.
     */
    private function checklist(): void
    {
        $config = $this->laravel['config'];
        $rows = [];

        $check = function (string $item, bool $ok, string $detail) use (&$rows) {
            $rows[] = [$ok ? '<fg=green>✓</>' : '<fg=yellow>!</>', $item, $detail];
        };

        $check('APP_DEBUG', ! $config->get('app.debug'), $config->get('app.debug')
            ? 'true: debug mode keeps view mtime checks and detailed error pages on; set false in production'
            : 'false');

        $check('APP_ENV', $config->get('app.env') === 'production', (string) $config->get('app.env'));

        $dbDefault = $config->get('database.default');
        $sqlite = $config->get("database.connections.{$dbDefault}.driver") === 'sqlite';

        $session = $config->get('session.driver');
        $check('Session driver', ! ($session === 'database' && $sqlite), $session === 'database' && $sqlite
            ? 'database on SQLite: a read + write query on every web request; consider file, redis or cookie'
            : $session);

        $cache = $config->get('cache.default');
        $check('Cache store', ! ($config->get("cache.stores.{$cache}.driver") === 'database' && $sqlite), $config->get("cache.stores.{$cache}.driver") === 'database' && $sqlite
            ? 'database on SQLite: every cache read is a query; consider file or redis'
            : $cache);

        $check('Log level', $config->get('logging.channels.'.$config->get('logging.default').'.level', 'debug') !== 'debug' || ! $config->get('app.debug'),
            (string) $config->get('logging.channels.'.$config->get('logging.default').'.level', 'debug'));

        $check('realpath_cache_size', $this->bytes((string) ini_get('realpath_cache_size')) >= 4 * 1024 * 1024, (string) ini_get('realpath_cache_size').' (4M+ recommended)');

        $this->newLine();
        $this->line('  <options=bold>Production checklist</>');
        $this->table(['', 'Item', 'Status'], $rows);
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
