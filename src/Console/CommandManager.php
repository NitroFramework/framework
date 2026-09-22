<?php

namespace Nitro\Console;

use Nitro\Concurrency\Console\ConcurrencyInvokeCommand;
use Nitro\Cache\Repository;
use Nitro\Console\Contracts\Isolatable;
use Nitro\Console\Events\CommandEvent;
use Nitro\Console\Events\ConsoleEvents;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Foundation\Contracts\PathRegistry;
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
class CommandManager implements CommandRunner, ReceivesDispatcher
{
    /** The bus command events are raised on; null until one is given. */
    private ?Dispatcher $events = null;

    /** Where isolated commands hold their lock; null until one is given. */
    private ?Repository $locks = null;

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
        return $this->dispatch($name, $arguments, Verbosity::fromArguments($arguments));
    }

    /**
     * Run a command on behalf of another one.
     *
     * The verbosity is the caller's, so a command run quietly stays quiet
     * however loud the command it runs would normally be.
     *
     * @param array<int, string> $arguments
     */
    public function call(string $command, array $arguments = [], Verbosity $verbosity = Verbosity::Normal): int
    {
        return $this->dispatch($command, $arguments, $verbosity);
    }

    /**
     * Give the console the bus it raises its events on.
     *
     * Asked for rather than assumed, like the router's: a console with no
     * dispatcher simply raises nothing.
     */
    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->events = $dispatcher;
    }

    /** @param array<int, string> $arguments */
    private function dispatch(string $name, array $arguments, Verbosity $verbosity): int
    {
        if (!isset($this->commands[$name])) {
            throw new \Exception("Command '{$name}' not found.");
        }

        $event = new CommandEvent($name, $arguments, $verbosity);

        $this->events?->dispatch(ConsoleEvents::STARTING, $event);

        try {
            $code = $this->runCommand($name, $arguments, $verbosity);
        } finally {
            // In a finally so a command that throws is still reported as
            // finished: a listener timing or auditing commands should not lose
            // the failures, which are the ones worth hearing about.
            $this->events?->dispatch(
                ConsoleEvents::FINISHED,
                $event->finished($code ?? ExitCode::FAILURE)
            );
        }

        return $code;
    }

    /**
     * Give the console the cache its isolated commands lock in.
     *
     * Optional: without one, `--isolated` cannot be honoured and says so
     * rather than running unlocked, since a command that asked not to overlap
     * and silently overlapped is worse than one that refuses to start.
     */
    public function setLockStore(Repository $cache): void
    {
        $this->locks = $cache;
    }

    /**
     * Run the command, holding its lock if it asked to be isolated.
     *
     * @param array<int, string> $arguments
     */
    private function runCommand(string $name, array $arguments, Verbosity $verbosity): int
    {
        $entry = $this->commands[$name];

        $command = is_string($entry) ? $this->resolver->resolve($entry) : $entry;

        if (! $command instanceof Isolatable || ! in_array('--isolated', $arguments, true)) {
            return $this->execute($name, $command, $arguments, $verbosity);
        }

        if ($this->locks === null) {
            $this->output->error(
                "[{$name}] asked to run isolated, but no cache is configured to hold the lock. "
                . 'Configure a cache store, or drop --isolated.'
            );

            return ExitCode::FAILURE;
        }

        $lock = new CommandLock($this->locks, $name, $command->isolationSeconds());

        if (! $lock->acquire()) {
            if ($verbosity->allows(Verbosity::Normal)) {
                $this->output->info("[{$name}] is already running elsewhere; nothing to do.");
            }

            // Success: the work is in hand, which is what a scheduler needs to
            // know. A failure code here would page someone every time two runs
            // overlapped as designed.
            return ExitCode::SUCCESS;
        }

        try {
            $code = $this->execute($name, $command, $arguments, $verbosity);
        } finally {
            $lock->release();
        }

        return $code;
    }

    /**
     * @param  object             $command
     * @param  array<int, string> $arguments
     */
    private function execute(string $name, object $command, array $arguments, Verbosity $verbosity): int
    {
        // Removed before the command parses its own signature: --isolated is
        // the dispatcher's flag, not the command's, and would otherwise read
        // as an option the command never declared.
        $arguments = array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => $argument !== '--isolated'
        ));

        // Two shapes are supported: a single Command (its own signature +
        // handle()), or a grouped CommandInterface (handle(sig, args)). Both
        // return an exit code, and both codes reach the shell — a grouped
        // command used to return void and be reported as 0 whatever it printed,
        // so a refused db:wipe and a failed migration both looked like success
        // to CI.
        if ($command instanceof Command) {
            $command->setRunner($this);

            return (int) $command->run($arguments, $verbosity);
        }

        // A grouped command writes through the OutputFormatter, which echoes,
        // so silencing one is a matter of catching the buffer rather than
        // asking it to keep quiet.
        if ($verbosity === Verbosity::Quiet) {
            ob_start();

            try {
                return $command->handle($name, $arguments);
            } finally {
                ob_end_clean();
            }
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
