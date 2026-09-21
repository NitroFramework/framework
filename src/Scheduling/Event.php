<?php

namespace Nitro\Scheduling;

use Closure;
use DateTimeInterface;

/**
 * A scheduled task: a cron expression plus the thing to run (a callback, a
 * console command, or a queued job) and optional when()/skip() constraints.
 */
class Event
{
    use ManagesFrequencies;

    /** [minute, hour, day-of-month, month, day-of-week] */
    protected array $fields = ['*', '*', '*', '*', '*'];

    /** Timezone the expression is evaluated in; null uses the application's. */
    protected ?string $timezone = null;

    /** Environments this task runs in; empty means all of them. */
    protected array $environments = [];

    /** @var array<int, callable> Run before the task. */
    protected array $beforeCallbacks = [];

    /** @var array<int, callable> Run after the task, whatever it did. */
    protected array $afterCallbacks = [];

    /** @var array<int, callable> Run when the task completes without throwing. */
    protected array $successCallbacks = [];

    /** @var array<int, callable> Run when the task throws. */
    protected array $failureCallbacks = [];

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

    public function expression(): string
    {
        return implode(' ', $this->fields);
    }

    // ─── constraints ──────────────────────────────────────

    public function when(callable $callback): static { $this->filters[] = $callback; return $this; }
    public function skip(callable $callback): static { $this->rejects[] = $callback; return $this; }

    /**
     * Restrict the task to the given environments.
     *
     * @param string|array<int, string> $environments
     */
    public function environments(string|array $environments): static
    {
        $this->environments = is_array($environments) ? $environments : func_get_args();

        return $this;
    }

    /**
     * Determine whether the task runs in the given environment.
     */
    public function runsInEnvironment(string $environment): bool
    {
        return $this->environments === [] || in_array($environment, $this->environments, true);
    }

    // ─── hooks ────────────────────────────────────────────

    /** Run a callback before the task. */
    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /** Run a callback after the task, whether or not it threw. */
    public function after(callable $callback): static
    {
        return $this->then($callback);
    }

    /** Run a callback after the task, whether or not it threw. */
    public function then(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /** Run a callback when the task completes without throwing. */
    public function onSuccess(callable $callback): static
    {
        $this->successCallbacks[] = $callback;

        return $this;
    }

    /** Run a callback when the task throws. */
    public function onFailure(callable $callback): static
    {
        $this->failureCallbacks[] = $callback;

        return $this;
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

    /**
     * Determine whether the task is due, and passes every constraint.
     *
     * An event with its own timezone has `$now` converted into it first, so a
     * task written as 02:00 in one zone does not drift with the server's.
     */
    public function isDue(DateTimeInterface $now): bool
    {
        if ($this->timezone !== null) {
            $now = \DateTimeImmutable::createFromInterface($now)
                ->setTimezone(new \DateTimeZone($this->timezone));
        }

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

    public function run(ScheduleContext $context): mixed
    {
        if ($this->onOneServer && ! $this->claimThisMinute($context)) {
            return null;
        }

        if ($this->withoutOverlapping !== null) {
            return $context->cache()->store()->lock(
                $this->mutexName(),
                $this->withoutOverlapping,
                fn (): mixed => $this->execute($context),
            );
        }

        return $this->execute($context);
    }

    /**
     * Claim the current minute for this task, returning false when another
     * instance already holds it.
     */
    protected function claimThisMinute(ScheduleContext $context): bool
    {
        return $context->cache()->store()->add(
            $this->mutexName() . ':' . date('YmdHi'),
            1,
            60,
        );
    }

    /**
     * Run the task, firing its hooks around it.
     *
     * The after callbacks run in a `finally`, so a task that throws still gets
     * its cleanup; the failure callbacks see the exception before it is
     * re-thrown, so nothing is swallowed.
     */
    protected function execute(ScheduleContext $context): mixed
    {
        $this->fire($this->beforeCallbacks);

        try {
            $result = match ($this->type) {
                'callback' => ($this->task)(),
                'command'  => $this->runCommand($context),
                'job'      => $context->queue()->push($this->task),
                'exec'     => $this->runExec(),
                default    => null,
            };
        } catch (\Throwable $exception) {
            $this->fire($this->failureCallbacks, [$exception]);

            throw $exception;
        } finally {
            $this->fire($this->afterCallbacks);
        }

        $this->fire($this->successCallbacks, [$result]);

        return $result;
    }

    /**
     * Call each of a set of hooks.
     *
     * @param array<int, callable> $callbacks
     * @param array<int, mixed>    $arguments
     */
    protected function fire(array $callbacks, array $arguments = []): void
    {
        foreach ($callbacks as $callback) {
            $callback(...$arguments);
        }
    }

    protected function runCommand(ScheduleContext $context): mixed
    {
        $parts = preg_split('/\s+/', trim((string) $this->task));
        $name = array_shift($parts);

        return $context->commands()->resolve($name, $parts);
    }

    protected function runExec(): mixed
    {
        return shell_exec((string) $this->task);
    }
}
