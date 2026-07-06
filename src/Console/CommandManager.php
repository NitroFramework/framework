<?php

namespace Nitro\Console;

use Nitro\Container\Contracts\ContainerInterface;

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
        private ContainerInterface $container,
        private OutputFormatter $output
    ) {
        $this->registerBuiltInCommands();
        $this->discoverUserCommands();
    }

    private function registerBuiltInCommands(): void
    {
        // Just map signatures to classes. No 'new' here.
        $builtIns = [
            Commands\RouteCommands::class,
            Commands\ViewCommands::class,
            Commands\ConfigCacheCommand::class,
            Commands\OpcacheCommands::class,
            Commands\MigrationCommands::class,
            Commands\MakeCommands::class,
            Commands\OptimizeCommand::class,
            Commands\KeyGenerateCommand::class,
            Commands\ServeCommand::class,
            \Nitro\Thrust\Commands\ThrustCommands::class,
            Commands\HtmxCommands::class,
            Commands\QueueCommands::class,
            Commands\SeederCommands::class,
            Commands\FactoryCommands::class,
            Commands\DatabaseCommands::class,
            Commands\CacheCommands::class,
        ];

        foreach ($builtIns as $class) {
            // We temporarily use the container to get the signatures without
            // "running" the command logic yet.
            $instance = $this->container->make($class);
            foreach ($instance->getCommands() as $signature => $description) {
                $this->commands[$signature] = $class;
                $this->descriptions[$signature] = $description;
            }
        }

        // Special case for help
        $this->commands['help'] = Commands\HelpCommand::class;
        $this->descriptions['help'] = 'Show this help message';
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
        $command = is_string($entry) ? $this->container->make($entry) : $entry;

        // Two shapes are supported: a Laravel-style single Command (its own
        // signature + handle()), or a grouped CommandInterface (handle(sig, args)).
        // Return the command's exit code so the shell sees success/failure —
        // dropping it made `php nitro …` always exit 0, so CI treats a failed
        // migration as success.
        if ($command instanceof Command) {
            return (int) $command->run($arguments);
        }

        // Grouped CommandInterface::handle() is void — a clean return is success.
        $command->handle($name, $arguments);
        return 0;
    }

    /**
     * Discover application commands under app/Console/Commands (recursively).
     * A class may be a Laravel-style Command (its name/description read from the
     * signature without instantiating) or a grouped CommandInterface.
     */
    private function discoverUserCommands(): void
    {
        $paths = $this->container->get('paths');
        $base = $paths->base();
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
            $instance = $this->container->make($className);
            foreach ($instance->getCommands() as $signature => $description) {
                $this->commands[$signature] = $className;
                $this->descriptions[$signature] = $description;
            }
        }
    }
}
