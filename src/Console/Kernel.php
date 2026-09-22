<?php

namespace Nitro\Console;

use Nitro\Container\Container;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Exceptions\ExceptionHandler;
use Throwable;

/**
 * The console kernel — parses argv and dispatches to the CommandManager.
 */
class Kernel
{
    /**
     * The dispatcher is taken here rather than wired in a provider's boot()
     * because resolving the CommandManager builds it, and building it scans
     * the filesystem for commands. Only a console run should pay that, and
     * only a console run constructs this kernel.
     */
    public function __construct(
        protected OutputFormatter $output,
        protected CommandManager $commandManager,
        protected ?Dispatcher $events = null,
    ) {}

    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);

        if ($this->events !== null) {
            $this->commandManager->setDispatcher($this->events);
        }

        try {
            return $this->commandManager->resolve($commandName, $arguments);
        } catch (Throwable $exception) {
            // Any failure — unknown command, missing argument, command throwing —
            // exits non-zero so scripts and CI can detect it.
            $this->renderException($exception);
            return 1;
        }
    }

    /**
     * Report the failure, then print it usefully.
     *
     * Both halves matter. Reporting is what leaves a record when a scheduled
     * command dies at 3am with nobody watching; the trace is what makes a
     * failure debuggable instead of a bare "Error: SQLSTATE[HY000]" with no
     * indication of where it came from.
     */
    protected function renderException(Throwable $exception): void
    {
        $handler = $this->exceptionHandler();

        $handler?->report($exception);

        if ($handler !== null) {
            $this->output->error(rtrim($handler->renderForConsole($exception)));
        } else {
            $this->output->error('Error: ' . $exception->getMessage());
        }

        $this->output->writeln('');
        $this->output->info("Run 'php nitro help' to see available commands.");
    }

    /** The handler, or null if the container isn't up (very early failures). */
    protected function exceptionHandler(): ?ExceptionHandler
    {
        try {
            $container = Container::getInstance();

            return $container->has(ExceptionHandler::class)
                ? $container->resolve(ExceptionHandler::class)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
