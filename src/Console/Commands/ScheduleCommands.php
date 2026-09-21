<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use DateTime;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Scheduling\Schedule;
use Throwable;

/**
 * schedule:run (run due tasks), schedule:work (run them every minute) and
 * schedule:list (show defined tasks).
 */
class ScheduleCommands implements CommandInterface
{
    /** Set by SIGTERM/SIGINT so schedule:work finishes its tick and exits. */
    private bool $shouldStop = false;

    public function __construct(
        private Container $container,
        private OutputFormatter $output,
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'schedule:run'  => 'Run the scheduled tasks that are currently due',
            'schedule:work' => 'Run due tasks every minute (long-running; no cron needed)',
            'schedule:list' => 'List the defined scheduled tasks',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments): int
    {
        return match ($command) {
            'schedule:run'  => $this->run(),
            'schedule:work' => $this->work(),
            'schedule:list' => $this->list(),
            default         => $this->invalidSignature("Unknown schedule command: {$command}"),
        };
    }

    protected function run(): int
    {
        $due = $this->schedule()->dueEvents(new DateTime());

        if ($due === []) {
            $this->output->info('No scheduled tasks are due.');
            return ExitCode::SUCCESS;
        }

        foreach ($due as $event) {
            $this->output->info('Running: ' . $event->getDescription());
            try {
                $event->run();
                $this->output->success('Done: ' . $event->getDescription());
            } catch (Throwable $exception) {
                $this->output->error('Failed: ' . $event->getDescription() . ' — ' . $exception->getMessage());
            }
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Run due tasks once a minute until the process is told to stop.
     *
     * This is the scheduler for a host that has no cron of its own — a
     * container platform, where the way to get something running every minute
     * is to keep a process alive rather than to edit a crontab. It ticks on the
     * wall clock, not on a fixed sleep, so a slow minute does not push every
     * later tick out of alignment with the cron expressions.
     */
    protected function work(): int
    {
        $this->installSignalHandlers();

        $this->output->info('Scheduler started. Running due tasks every minute.');

        while (! $this->shouldStop) {
            $this->run();

            $this->sleepUntilNextMinute();
        }

        $this->output->info('Scheduler stopped.');

        return ExitCode::SUCCESS;
    }

    protected function list(): int
    {
        $events = $this->schedule()->events();

        if ($events === []) {
            $this->output->info('No scheduled tasks are defined.');
            return ExitCode::SUCCESS;
        }

        foreach ($events as $event) {
            $this->output->writeln(str_pad($event->expression(), 20) . $event->getDescription());
        }

        return ExitCode::SUCCESS;
    }

    protected function schedule(): Schedule
    {
        return $this->container->resolve(Schedule::class);
    }

    /**
     * Wait for the top of the next minute, in one-second steps so a signal is
     * acted on straight away rather than up to a minute later.
     */
    private function sleepUntilNextMinute(): void
    {
        $wake = (int) (floor(time() / 60) + 1) * 60;

        while (! $this->shouldStop && time() < $wake) {
            sleep(1);
        }
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function (): void { $this->shouldStop = true; };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        if (defined('SIGQUIT')) {
            pcntl_signal(SIGQUIT, $stop);
        }
    }
    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
