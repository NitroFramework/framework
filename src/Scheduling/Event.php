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

    /** Seconds an overlap lock may be held, or null when overlapping is allowed. */
    protected ?int $withoutOverlapping = null;

    /** Whether only one instance may run this task per due minute. */
    protected bool $onOneServer = false;

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

    /**
     * Let only one run of this task be in flight at a time.
     *
     * A task that occasionally takes longer than its own interval would
     * otherwise start again on top of itself. The lock is held for the duration
     * of the run and released afterwards, whatever the task does; $expiresAfter
     * only bounds how long a run that dies without releasing can block the next
     * one, so it should exceed the task's worst-case runtime.
     *
     * @param int $expiresAfter Minutes before an unreleased lock lapses.
     */
    public function withoutOverlapping(int $expiresAfter = 1440): static
    {
        $this->withoutOverlapping = max(1, $expiresAfter) * 60;

        return $this;
    }

    /**
     * Run this task on one instance only.
     *
     * Every replica runs the scheduler, so without this a task due at 03:00
     * runs once per replica. The lock is keyed by the minute the task is due
     * and is deliberately never released — the first instance to claim that
     * minute is the one that runs, and the key lapses on its own.
     *
     * Requires a cache store every instance shares (redis or database); a
     * per-instance store cannot coordinate anything.
     */
    public function onOneServer(): static
    {
        $this->onOneServer = true;

        return $this;
    }

    /** A name for this task's locks, stable across runs and processes. */
    public function mutexName(): string
    {
        return 'schedule:' . sha1($this->type . '|' . $this->expression() . '|' . $this->getDescription());
    }

    public function run(ContainerInterface $container): mixed
    {
        if ($this->onOneServer && ! $this->claimThisMinute($container)) {
            return null;
        }

        if ($this->withoutOverlapping !== null) {
            return $container->createOrResolve('cache')->store()->lock(
                $this->mutexName(),
                $this->withoutOverlapping,
                fn (): mixed => $this->execute($container),
            );
        }

        return $this->execute($container);
    }

    /**
     * Claim the current minute for this task, returning false when another
     * instance already holds it.
     */
    protected function claimThisMinute(ContainerInterface $container): bool
    {
        return $container->createOrResolve('cache')->store()->add(
            $this->mutexName() . ':' . date('YmdHi'),
            1,
            60,
        );
    }

    protected function execute(ContainerInterface $container): mixed
    {
        return match ($this->type) {
            'callback' => ($this->task)(),
            'command'  => $this->runCommand($container),
            'job'      => $container->createOrResolve('queue')->push($this->task),
            'exec'     => $this->runExec(),
            default    => null,
        };
    }

    protected function runCommand(ContainerInterface $container): mixed
    {
        $parts = preg_split('/\s+/', trim((string) $this->task));
        $name = array_shift($parts);

        return $container->createOrResolve(CommandManager::class)->resolve($name, $parts);
    }

    protected function runExec(): mixed
    {
        return shell_exec((string) $this->task);
    }
}
