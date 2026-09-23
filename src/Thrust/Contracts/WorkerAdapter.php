<?php

namespace Nitro\Thrust\Contracts;

use Closure;

/**
 * One runtime's way of handing an already-booted worker its next request.
 *
 * The runtimes disagree about who owns the loop. FrankenPHP is asked: the
 * worker calls a function that blocks until a request arrives and returns false
 * when the server is done with it. Swoole is told: a server is built, a handler
 * is registered against it, and control does not come back until it stops.
 *
 * So the loop belongs here, not to the caller. {@see serve()} is given what to
 * do with a request and does not return until the worker should end — which
 * both models can express, where a single "fetch me one request" could not.
 */
interface WorkerAdapter
{
    /** Whether this runtime is the one the process is running under. */
    public function isAvailable(): bool;

    /** What to say when it is not, naming how to start the worker properly. */
    public function unavailableReason(): string;

    /**
     * Serve requests until the runtime stops or $handle asks to.
     *
     * @param Closure $handle Given a Nitro request, answers with a Nitro
     *                        response. Returning null means the request was
     *                        already answered and there is nothing to send.
     * @param Closure $after  Run once per served request, for the bookkeeping
     *                        a worker does between them — counting, resetting,
     *                        deciding whether this was the last. Answers false
     *                        when the worker should stop.
     */
    public function serve(Closure $handle, Closure $after): void;
}
