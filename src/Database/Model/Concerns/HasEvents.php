<?php

namespace Nitro\Database\Model\Concerns;

/**
 * Model lifecycle events — Laravel's creating/created/updating/updated/
 * saving/saved/deleting/deleted (+ restoring/restored for soft deletes).
 *
 * Register a listener per model class, or an observer object:
 *
 *   User::creating(fn (User $u) => $u->uuid ??= bin2hex(random_bytes(8)));
 *   User::observe(UserObserver::class);
 *
 * A "*ing" listener that returns false halts the operation (the save/delete is
 * aborted). Listeners are keyed by concrete class; firing is a cheap no-op when
 * none are registered, so the CRUD hot path is unaffected.
 */
trait HasEvents
{
    /**
     * Listeners, keyed by class → event → callbacks. Static, so registration
     * persists for the process (e.g. set up in a service provider's boot()).
     *
     * @var array<string, array<string, list<callable>>>
     */
    protected static array $modelEventCallbacks = [];

    /** Events a model (incl. soft deletes) may fire. */
    protected static array $observableEvents = [
        'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
    ];

    public static function creating(callable $cb): void { static::registerModelEvent('creating', $cb); }
    public static function created(callable $cb): void { static::registerModelEvent('created', $cb); }
    public static function updating(callable $cb): void { static::registerModelEvent('updating', $cb); }
    public static function updated(callable $cb): void { static::registerModelEvent('updated', $cb); }
    public static function saving(callable $cb): void { static::registerModelEvent('saving', $cb); }
    public static function saved(callable $cb): void { static::registerModelEvent('saved', $cb); }
    public static function deleting(callable $cb): void { static::registerModelEvent('deleting', $cb); }
    public static function deleted(callable $cb): void { static::registerModelEvent('deleted', $cb); }
    public static function restoring(callable $cb): void { static::registerModelEvent('restoring', $cb); }
    public static function restored(callable $cb): void { static::registerModelEvent('restored', $cb); }

    /**
     * Register an observer object: each method named after an event becomes a
     * listener for it.
     */
    public static function observe(string|object $observer): void
    {
        $instance = is_string($observer) ? new $observer : $observer;

        foreach (static::$observableEvents as $event) {
            if (method_exists($instance, $event)) {
                static::registerModelEvent($event, [$instance, $event]);
            }
        }
    }

    protected static function registerModelEvent(string $event, callable $callback): void
    {
        static::$modelEventCallbacks[static::class][$event][] = $callback;
    }

    /**
     * Fire an event for this model. Returns false if any "*ing" listener
     * vetoed the operation, so callers can abort.
     */
    protected function fireModelEvent(string $event): bool
    {
        foreach (static::$modelEventCallbacks[static::class][$event] ?? [] as $callback) {
            if ($callback($this) === false) {
                return false;
            }
        }
        return true;
    }

    /** Drop all registered listeners (test isolation). */
    public static function flushEventListeners(): void
    {
        unset(static::$modelEventCallbacks[static::class]);
    }
}
