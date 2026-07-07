<?php

namespace Nitro\Console\Commands;

use DateTime;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Scheduling\Schedule;
use Throwable;

/** schedule:run (run due tasks) and schedule:list (show defined tasks). */
class ScheduleCommands implements CommandInterface
{
    public function __construct(
        private ContainerInterface $container,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'schedule:run'  => 'Run the scheduled tasks that are currently due',
            'schedule:list' => 'List the defined scheduled tasks',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'schedule:run'  => $this->run(),
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
            } catch (Throwable $e) {
                $this->output->error('Failed: ' . $event->getDescription() . ' — ' . $e->getMessage());
            }
        }
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
        return $this->container->make(Schedule::class);
    }
}
