<?php

namespace Nitro\Events\Contracts;

/**
 * A dispatcher that can be muted and unmuted wholesale.
 *
 * Useful for a bulk import or a seeding run, where firing a model event per
 * row is the difference between seconds and minutes. Optional, and separate
 * from {@see Dispatcher}, because being silenceable is not part of being a
 * dispatcher: an implementation that forwards to an external bus has nothing
 * to mute, and should not have to write three empty methods to say so.
 *
 * Asked for with instanceof, never assumed. A dispatcher that does not offer
 * it counts as always enabled, which is the safe reading — the alternative is
 * events silently not firing because the check could not be made.
 */
interface TogglesEvents
{
    /** Resume dispatching. */
    public function enable(): void;

    /** Suppress dispatching until {@see enable()} is called. */
    public function disable(): void;

    /** Whether events are currently being dispatched. */
    public function isEnabled(): bool;
}
