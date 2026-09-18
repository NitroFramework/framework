<?php

namespace Nitro\Console\Commands;

use DateTime;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface;
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
        private ContainerInterface $container,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'schedule:run'  => 'Run the scheduled tasks that are currently due',
            'schedule:work' => 'Run due tasks every minute (long-running; no cron needed)',
            'schedule:list' => 'List the defined scheduled tasks',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'schedule:run'  => $this->run(),
            'schedule:work' => $this->work(),
            'schedule:list' => $this->list(),
            default         => $this->output->error("Unknown schedule command: {$command}"),
        };
    }

    protected function run(): void
    {
        $due = $this->schedule()->dueEvents(new DateTime());

        if ($due === []) {
            $this->output->info('No scheduled tasks are due.');
            return;
        }

        foreach ($due as $event) {
            $this->output->info('Running: ' . $event->getDescription());
            try {
                $event->run($this->container);
                $this->output->success('Done: ' . $event->getDescription());
            } catch (Throwable $exception) {
                $this->output->error('Failed: ' . $event->getDescription() . ' — ' . $exception->getMessage());
            }
        }
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
    protected function work(): void
    {
        $this->installSignalHandlers();

        $this->output->info('Scheduler started. Running due tasks every minute.');

        while (! $this->shouldStop) {
            $this->run();

            $this->sleepUntilNextMinute();
        }

        $this->output->info('Scheduler stopped.');
    }

    protected function list(): void
    {
        $events = $this->schedule()->events();

        if ($events === []) {
            $this->output->info('No scheduled tasks are defined.');
            return;
        }

        foreach ($events as $event) {
            $this->output->writeln(str_pad($event->expression(), 20) . $event->getDescription());
        }
    }

    protected function schedule(): Schedule
    {
        return $this->container->createOrResolve(Schedule::class);
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
}
