<?php

namespace Nitro\Events\Contracts;

/**
 * A class that emits events and must be handed the bus to emit them on.
 *
 * Composing {@see \Nitro\Events\Concerns\DispatchesEvents} gives a class the
 * ability to raise events, but not a dispatcher to raise them on — and an
 * emitter holding no dispatcher does not fail, it goes quiet. The router
 * composed that trait and called eventLazy() three times while nothing ever
 * gave it a bus, so route:matched and route:dispatching had never once fired
 * and nothing anywhere said so.
 *
 * Declaring this is how a layer asks to be wired. The composition root looks
 * for it, so the answer to "who hands this one a dispatcher?" is the same for
 * every layer instead of being remembered case by case.
 */
interface ReceivesDispatcher
{
    /** Give this object the bus it should raise its events on. */
    public function setDispatcher(Dispatcher $dispatcher): void;
}
