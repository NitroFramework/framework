<?php

namespace Nitro\Concurrency;

use Closure;
use Nitro\Container\Container;

/**
 * Normalises and runs a single "task" through the container so DI works the same
 * whether the task runs in-process (SyncDriver) or in a subprocess (ProcessDriver).
 *
 * A task is one of:
 *   - a Closure / callable            (in-process only — closures can't cross a process boundary)
 *   - an invokable class-string       'App\\Tasks\\BuildReport'      -> resolved and __invoke()d
 *   - [class-string, method]          [ReportService::class, 'daily']
 *   - [class-string, method, args]    [ReportService::class, 'for', [$userId]]
 *
 * The class-string forms are SERIALISABLE, which is what lets ProcessDriver ship
 * them to a fresh worker; a Closure is not, so it's rejected there (see ProcessDriver).
 */
class TaskInvoker
{
    public static function invoke(mixed $task): mixed
    {
        $container = Container::getInstance();

        // Closure / already-bound callable — call through the container so its
        // parameters are auto-wired, matching how controllers are dispatched.
        if ($task instanceof Closure) {
            return $container->call($task);
        }

        // Invokable class-string: 'App\Tasks\Foo' -> (new Foo)()
        if (is_string($task) && class_exists($task)) {
            return $container->call([$container->make($task), '__invoke']);
        }

        // [class-or-object, method, ...args]
        if (is_array($task) && isset($task[0], $task[1])) {
            $target = is_string($task[0]) ? $container->make($task[0]) : $task[0];
            $method = $task[1];
            $args   = $task[2] ?? [];

            return $container->call([$target, $method], is_array($args) ? $args : [$args]);
        }

        // A plain callable (e.g. 'strlen' or [$obj, 'method']) — call as-is.
        if (is_callable($task)) {
            return $container->call($task);
        }

        throw new \InvalidArgumentException(
            'Concurrency task must be a Closure, an invokable class-string, or [class, method, args]. Got: '
            . get_debug_type($task)
        );
    }

    /** Whether a task can cross a process boundary (i.e. isn't a Closure/resource). */
    public static function isSerializable(mixed $task): bool
    {
        // A serialisable task is a class-string or a [class, method, args] array...
        if (! is_string($task) && ! is_array($task)) {
            return false;
        }

        // ...whose parts (including nested args) hold no closures or resources.
        return ! self::hasUnserializable($task);
    }

    /** Recursively detect a Closure or resource anywhere in a value. */
    private static function hasUnserializable(mixed $value): bool
    {
        if ($value instanceof Closure || is_resource($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $part) {
                if (self::hasUnserializable($part)) {
                    return true;
                }
            }
        }

        return false;
    }
}
