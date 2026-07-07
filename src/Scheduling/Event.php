<?php

namespace Nitro\Scheduling;

use Closure;
use DateTimeInterface;
use Nitro\Console\CommandManager;
use Nitro\Container\Contracts\ContainerInterface;

/**
 * A scheduled task: a cron expression plus the thing to run (a callback, a
 * console command, or a queued job) and optional when()/skip() constraints.
 */
class Event
{
    /** [minute, hour, day-of-month, month, day-of-week] */
    protected array $fields = ['*', '*', '*', '*', '*'];

    /** @var array<int, callable> Must all return true for the event to run. */
    protected array $filters = [];
    /** @var array<int, callable> If any returns true the event is skipped. */
    protected array $rejects = [];

    protected string $description = '';

    public function __construct(
        protected mixed $task,
        protected string $type = 'callback', // callback | command | job | exec
    ) {}

    // ─── frequency ────────────────────────────────────────

    public function cron(string $expression): static
    {
        $this->fields = preg_split('/\s+/', trim($expression));
        return $this;
    }

    public function everyMinute(): static { return $this->splice(1, '*'); }
    public function everyFiveMinutes(): static { return $this->splice(1, '*/5'); }
    public function everyTenMinutes(): static { return $this->splice(1, '*/10'); }
    public function everyThirtyMinutes(): static { return $this->splice(1, '*/30'); }

    public function hourly(): static { return $this->splice(1, '0'); }
    public function hourlyAt(int $minute): static { return $this->splice(1, (string) $minute); }

    public function daily(): static { return $this->splice(1, '0')->splice(2, '0'); }

    public function dailyAt(string $time): static
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        return $this->splice(1, (string) (int) $minute)->splice(2, (string) (int) $hour);
    }

    public function weekly(): static { return $this->daily()->splice(5, '0'); }
    public function weeklyOn(int $day, string $time = '0:0'): static { return $this->dailyAt($time)->splice(5, (string) $day); }
    public function monthly(): static { return $this->daily()->splice(3, '1'); }

    public function weekdays(): static { return $this->splice(5, '1-5'); }
    public function weekends(): static { return $this->splice(5, '0,6'); }

    /** @param int|array<int,int> $days */
    public function days(int|array $days): static
    {
        return $this->splice(5, implode(',', (array) $days));
    }

    protected function splice(int $position, string $value): static
    {
        $this->fields[$position - 1] = $value;
        return $this;
    }

    public function expression(): string
    {
        return implode(' ', $this->fields);
    }

    // ─── constraints ──────────────────────────────────────

    public function when(callable $callback): static { $this->filters[] = $callback; return $this; }
    public function skip(callable $callback): static { $this->rejects[] = $callback; return $this; }

    public function between(string $start, string $end): static
    {
        return $this->when(function () use ($start, $end): bool {
            $now = date('H:i');
            return $now >= $start && $now <= $end;
        });
    }

    public function description(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description ?: (is_string($this->task) ? $this->task : $this->type);
    }

    // ─── due / run ────────────────────────────────────────

    public function isDue(DateTimeInterface $now): bool
    {
        if (! (new CronExpression($this->expression()))->isDue($now)) {
            return false;
        }

        foreach ($this->filters as $filter) {
            if (! $filter()) {
                return false;
            }
        }
        foreach ($this->rejects as $reject) {
            if ($reject()) {
                return false;
            }
        }

        return true;
    }

    public function run(ContainerInterface $container): mixed
    {
        return match ($this->type) {
            'callback' => ($this->task)(),
            'command'  => $this->runCommand($container),
            'job'      => $container->make('queue')->push($this->task),
            'exec'     => $this->runExec(),
            default    => null,
        };
    }

    protected function runCommand(ContainerInterface $container): mixed
    {
        $parts = preg_split('/\s+/', trim((string) $this->task));
        $name = array_shift($parts);

        return $container->make(CommandManager::class)->resolve($name, $parts);
    }

    protected function runExec(): mixed
    {
        return shell_exec((string) $this->task);
    }
}
