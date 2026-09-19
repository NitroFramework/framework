<?php

namespace Nitro\Database\Model;

use Nitro\Events\Dispatcher;

/**
 * Process-wide model state: which classes have booted, and the dispatcher their
 * events route through.
 *
 * Both belong to the model layer as a whole rather than to any one model, and
 * {@see \Nitro\Foundation\Providers\DatabaseServiceProvider} has to set them
 * before the first model is built. Holding them on {@see Model} made that a
 * problem: Model composes six Concerns traits, and touching any static member
 * loads the class and every trait with it — so a request that never queried
 * anything still paid for the whole model layer to reset an array.
 *
 * This class carries nothing but the two slots, so the provider loads one small
 * file and Model is loaded when a model is actually used.
 *
 * Not the public API: application code goes through Model::setEventDispatcher()
 * and friends, which forward here.
 */
final class ModelState
{
    /**
     * Classes whose boot() has already run, keyed by class name.
     *
     * Keyed rather than a flat list because booting is per concrete class: a
     * parent and its subclass each get their own boot(), and a subclass must not
     * be considered booted just because its parent was.
     *
     * @var array<class-string, true>
     */
    private static array $booted = [];

    /** Where model events are dispatched, or null when none is set. */
    private static ?Dispatcher $dispatcher = null;

    /** Whether this exact class has already run its boot(). */
    public static function hasBooted(string $class): bool
    {
        return isset(self::$booted[$class]);
    }

    /** Mark a class booted. Called before boot() runs, so a re-entrant construct sees it. */
    public static function markBooted(string $class): void
    {
        self::$booted[$class] = true;
    }

    /**
     * Forget every booted class.
     *
     * A model's booted() hook registers its listeners against whichever
     * dispatcher was current when it ran, but the booted flag outlives the
     * application that set that dispatcher. Build a second application in the
     * same process — every test does, and so does a worker that rebuilds the app
     * — and the models stay marked booted while their listeners sit on a
     * dispatcher nothing fires any more. Every model-level guard then silently
     * stops applying: an immutable record accepts an update and reports success.
     */
    public static function clearBooted(): void
    {
        self::$booted = [];
    }

    public static function setDispatcher(?Dispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function dispatcher(): ?Dispatcher
    {
        return self::$dispatcher;
    }
}
