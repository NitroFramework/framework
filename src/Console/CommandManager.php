<?php

namespace Nitro\Console;

use Nitro\Concurrency\Console\ConcurrencyInvokeCommand;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\PathRegistry;
use Nitro\Cache\Console\CacheTableCommand;
use Nitro\Session\Console\SessionTableCommand;
use Nitro\Thrust\Commands\ThrustCommands;

/**
 * Registers and dispatches console commands.
 *
 * Discovers the framework's built-in command classes and the application's own
 * commands, maps each signature to its handling class, and resolves + runs the
 * invoked command through the container. Every command implements CommandInterface.
 */
class CommandManager
{
    /**
     * Now stores [signature => className] or [signature => object]
     */
    private array $commands = [];

    /**
     * Parallel map of [signature => description] for built-in and user
     * commands so HelpCommand can render the help table without holding a
     * back-reference to every command instance.
     */
    private array $descriptions = [];

    public function __construct(
        private ClassResolver $resolver,
        private OutputFormatter $output,
        private PathRegistry $paths,
    ) {
        $this->registerBuiltInCommands();
        $this->discoverPackageCommands();
        $this->discoverUserCommands();
    }

    /**
     * Console commands contributed by installed packages via `extra.nitro.commands`
     * (Laravel-style auto-discovery) — so a package's commands register on
     * `composer require` without touching the framework's built-in list.
     */
    private function discoverPackageCommands(): void
    {
        $paths = $this->paths;

        $manifest = new \Nitro\Foundation\PackageManifest(
            $paths->base('vendor'),
            $paths->base(),
            $paths->cachedPackages()
        );

        foreach ($manifest->config('commands') as $class) {
            if (is_string($class) && class_exists($class)) {
                $this->registerCommandClass($class);
            }
        }
    }

    private function registerBuiltInCommands(): void
    {
        // Just map signatures to classes. No 'new' here.
        $builtIns = [
            Commands\RouteCommands::class,
            Commands\ViewCommands::class,
            Commands\ConfigCacheCommand::class,
            Commands\MigrationCommands::class,
            Commands\MakeCommands::class,
            Commands\OptimizeCommand::class,
            Commands\PackageDiscoverCommand::class,
            Commands\KeyGenerateCommand::class,
            Commands\MaintenanceCommands::class,
            Commands\ServeCommand::class,
            ThrustCommands::class,
            Commands\QueueCommands::class,
            Commands\ScheduleCommands::class,
            Commands\SeederCommands::class,
            Commands\FactoryCommands::class,
            Commands\DatabaseCommands::class,
            Commands\CacheCommands::class,
            Commands\LifecycleCommand::class,
            Commands\RouteListCommand::class,
            Commands\VariableAuditCommand::class,
            Commands\LifetimeCheckCommand::class,
            ConcurrencyInvokeCommand::class,
            SessionTableCommand::class,
            CacheTableCommand::class,
        ];

        foreach ($builtIns as $class) {
            $this->mapSignatures($class);
        }

        // Special case for help
        $this->commands['help'] = Commands\HelpCommand::class;
        $this->descriptions['help'] = 'Show this help message';
    }

    /**
     * Record what a grouped command answers to, without building it.
     *
     * Reading the signatures used to mean resolving every command class through
     * the container — twenty-four constructions, with their dependencies, before
     * `php nitro help` could print a list. A class that declares its signatures
     * as the COMMANDS constant is read by reflection instead and is only built
     * if it is the one invoked.
     *
     * Falling back to an instance keeps a command that predates the constant
     * working; it pays the old cost, and only that command does.
     *
     * @param class-string $class
     */
    private function mapSignatures(string $class): void
    {
        $signatures = defined($class . '::COMMANDS')
            ? constant($class . '::COMMANDS')
            : $this->resolver->resolve($class)->getCommands();

        foreach ($signatures as $signature => $description) {
            $this->commands[$signature] = $class;
            $this->descriptions[$signature] = $description;
        }
    }

    /**
     * Map of every known [signature => description], used by HelpCommand.
     */
    public function getDescriptions(): array
    {
        return $this->descriptions;
    }

    public function resolve(string $name, array $arguments = []): int
    {
        if (!isset($this->commands[$name])) {
            throw new \Exception("Command '{$name}' not found.");
        }

        $entry = $this->commands[$name];

        // Class strings are built now (lazy) so a command's dependencies (and
        // HelpCommand's back-reference to this manager) resolve only on demand.
        $command = is_string($entry) ? $this->resolver->resolve($entry) : $entry;

        // Two shapes are supported: a single Command (its own signature +
        // handle()), or a grouped CommandInterface (handle(sig, args)). Both
        // return an exit code, and both codes reach the shell — a grouped
        // command used to return void and be reported as 0 whatever it printed,
        // so a refused db:wipe and a failed migration both looked like success
        // to CI.
        if ($command instanceof Command) {
            return (int) $command->run($arguments);
        }

        return $command->handle($name, $arguments);
    }

    /**
     * Discover application commands under app/Console/Commands (recursively).
     * A class may be a Laravel-style Command (its name/description read from the
     * signature without instantiating) or a grouped CommandInterface.
     */
    private function discoverUserCommands(): void
    {
        $base = $this->paths->base();
        $root = $base . '/app/Console/Commands';

        if (!is_dir($root)) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;

            // Map the file path back to its App\Console\Commands\... class.
            $relative = str_replace(['\\', '/'], '\\', substr($file->getPathname(), strlen($base) + 1));
            $className = 'App\\' . preg_replace('/\.php$/', '', substr($relative, strlen('app\\')));

            if (!class_exists($className)) continue;

            $this->registerCommandClass($className);
        }
    }

    /** Register a single command class by its shape. */
    private function registerCommandClass(string $className): void
    {
        if (is_subclass_of($className, Command::class)) {
            // Read the signature/description defaults without building the command.
            $defaults = (new \ReflectionClass($className))->getDefaultProperties();
            $signature = (string) ($defaults['signature'] ?? '');
            if ($signature === '') return;

            $name = Support\SignatureParser::parse($signature)['name'];
            $this->commands[$name] = $className;
            $this->descriptions[$name] = (string) ($defaults['description'] ?? '');
            return;
        }

        if (is_subclass_of($className, Contracts\CommandInterface::class)) {
            $this->mapSignatures($className);
        }
    }
}
