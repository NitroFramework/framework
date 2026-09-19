<?php

namespace Nitro\Events\Contracts;

/**
 * The event dispatcher, as the rest of the framework needs it.
 *
 * Model events, mail events, queue events and the framework's own lifecycle
 * hooks all go through an object held by another layer — so this is the seam
 * where the dependency crosses, and swapping the implementation means binding
 * something else to this.
 *
 * Deliberately six methods, derived from what the framework and the Event
 * facade actually call rather than from what the bundled Dispatcher exposes.
 * A contract that is smaller than its callers is the worst kind: it looks open
 * while an implementation that satisfies it completely still cannot boot.
 * A contract that copies every public method is barely better, because it
 * turns the bundled implementation's internals into a public promise —
 * enable(), disable() and flush() are nobody's business out here, and the
 * first two are offered separately as {@see TogglesEvents}.
 */
interface Dispatcher
{
    /**
     * Register a listener for one or more events.
     *
     * The listener may be a callable or a class name; naming a class is what
     * lets a route- or config-cached application avoid closures.
     */
    public function listen(string|array $events, callable|string $listener): void;

    /**
     * Register an object (or class name) whose subscribe() method registers
     * several listeners at once.
     */
    public function subscribe(string|object $subscriber): void;

    /**
     * Fire an event and return what the listeners gave back.
     *
     * @param  bool $halt Stop at the first listener returning a non-null value
     *         and return that, rather than collecting every response.
     */
    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed;

    /**
     * Fire an event and stop at the first non-null response — the form a
     * cancellable hook uses, where a listener returning false calls the whole
     * operation off.
     */
    public function until(string|object $event, mixed $payload = []): mixed;

    /** Drop every listener registered for an event. */
    public function forget(string $event): void;

    /**
     * Whether anything is listening.
     *
     * On the contract because it is a hot path, not a convenience: emitters
     * ask before building a payload, and an implementation that always said
     * true would cost every caller the work it was meant to avoid.
     */
    public function hasListeners(string $event): bool;
}
