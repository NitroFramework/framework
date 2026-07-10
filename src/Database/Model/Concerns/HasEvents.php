<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Events\Dispatcher;

/**
 * Model lifecycle events — creating/created, updating/updated, saving/saved,
 * deleting/deleted (+ restoring/restored for soft deletes).
 *
 * Events route through the app's Events\Dispatcher, keyed as
 * "model.{event}: {ClassName}", so they're first-class app events: any listener
 * (a service provider, a package, a wildcard "model.*") can react without
 * touching the model. The static registrars and observe() are sugar over that.
 *
 *   User::creating(fn (User $u) => $u->uuid ??= bin2hex(random_bytes(8)));
 *   User::observe(UserObserver::class);
 *   app('events')->listen('model.saved: App\Models\User', fn ($u) => ...);
 *
 * A "*ing" listener returning false halts the operation (save/delete aborts).
 * Optionally map an event to an object event via $dispatchesEvents on the model
 * (['created' => UserCreated::class]) to reach external/wildcard/queued listeners.
 *
 * Requires a dispatcher (set at boot via Model::setEventDispatcher()); with none
 * set, firing is a silent no-op so the CRUD path still works — matching Laravel.
 */
trait HasEvents
{
    protected static ?Dispatcher $dispatcher = null;

    /** Events a model (incl. soft deletes) may fire. */
    protected static array $observableEvents = [
        'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
    ];

    /** The "*ing" events that veto the operation when a listener returns false. */
    protected static array $haltingEvents = ['saving', 'creating', 'updating', 'deleting', 'restoring'];

    public static function setEventDispatcher(Dispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
    }

    public static function getEventDispatcher(): ?Dispatcher
    {
        return static::$dispatcher;
    }

    public static function unsetEventDispatcher(): void
    {
        static::$dispatcher = null;
    }

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
        static::$dispatcher?->listen(static::modelEventKey($event), $callback);
    }

    /**
     * Fire an event for this model. Returns false only when a "*ing" listener
     * vetoes the operation (returns false), so callers can abort.
     */
    protected function fireModelEvent(string $event): bool
    {
        if (static::$dispatcher === null) {
            return true;
        }

        // Object event (external/wildcard/queued listeners) — fire-and-forget.
        if (isset($this->dispatchesEvents[$event])) {
            static::$dispatcher->dispatch(new $this->dispatchesEvents[$event]($this));
        }

        $key = static::modelEventKey($event);

        // "*ing" events halt: the first non-null (false) response vetoes.
        if (in_array($event, static::$haltingEvents, true)) {
            return static::$dispatcher->until($key, $this) !== false;
        }

        static::$dispatcher->dispatch($key, $this);

        return true;
    }

    /** The dispatcher key for a model event, namespaced by concrete class. */
    protected static function modelEventKey(string $event): string
    {
        return "model.{$event}: " . static::class;
    }

    /** Drop all of this model's registered listeners (test isolation). */
    public static function flushEventListeners(): void
    {
        if (static::$dispatcher === null) {
            return;
        }

        foreach (static::$observableEvents as $event) {
            static::$dispatcher->forget(static::modelEventKey($event));
        }
    }
}
