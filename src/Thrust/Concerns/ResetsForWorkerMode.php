<?php

namespace Nitro\Thrust\Concerns;

use Nitro\Foundation\Contracts\ResetsBetweenRequests;
use Nitro\Support\Logger;
use Nitro\Thrust\Exceptions\CapturedRequestStateException;
use Nitro\Thrust\RequestStateTracker;
use Nitro\Thrust\WorkerMode;
use Throwable;

/**
 * Clears per-request state between worker iterations.
 *
 * Persistent services (router, view, config, …) survive, so every request after
 * the first pays only for request-scoped work.
 *
 * Nothing here names a subsystem. A service holding request state says so by
 * implementing ResetsBetweenRequests, and is found among the container's
 * resolved instances — so adding one is a change to that service alone. Listing
 * them here instead made this the single file that had to know about every
 * layer in the framework, and a subsystem missing from the list went on serving
 * the previous request's state with nothing to say so.
 */
trait ResetsForWorkerMode
{
    /** Watches for request state held past its request; null unless asked for. */
    private ?RequestStateTracker $requestStateTracker = null;

    /**
     * Reset request-scoped container instances and per-request state, so the
     * next request starts clean without paying for the full bootstrap again.
     */
    public function resetForWorkerMode(?WorkerMode $config = null): void
    {
        $scoped = $config?->scopedServices ?? ['request', 'auth', 'db', 'session'];

        // Looked for first, while what was captured is still reachable, and
        // reported last, once the reset has finished. Raising it here instead
        // would abandon the rest of the reset — leaving the worker part-way
        // cleaned for the next request, and the generation never advanced, so
        // one capture would be re-reported on every request after it. Empty
        // unless capture detection was turned on.
        $captured = $this->requestStateTracker?->captured() ?? [];

        // Clears the resolved instances but keeps the bindings, so the next
        // get('request') re-resolves from scratch.
        $this->container->forgetScoped($scoped);

        // Everything that declared itself scoped() at bind time. A feature opts
        // into per-request reset where it is registered, rather than by being
        // added to the list above.
        $this->container->forgetScopedInstances();

        $this->resetStatefulServices();

        $this->requestStateTracker?->startNewRequest();

        $this->reportCapturedState($captured);
    }

    /**
     * Start watching for request state that outlives its request.
     *
     * Off unless asked for: watching holds a reference to every request-lived
     * object and the scan reflects over the whole long-lived object graph.
     *
     * @param array<int, string> $scopedServices Names that live for one request.
     */
    public function detectCapturedState(array $scopedServices = ['request', 'auth', 'db', 'session', 'cookie']): void
    {
        $this->requestStateTracker = (new RequestStateTracker($this->container, $scopedServices))->watch();
    }

    /**
     * Raise anything long-lived that was still holding an object from the
     * request which just ended.
     *
     * A capture is not something to keep serving through: the holder answers
     * every later request from that one's data, so the earlier it is heard
     * about the less of it there is to unpick. Loud in a suite or a developer's
     * worker, and never armed in production — see
     * Container::detectCapturedState().
     *
     * @param array<int, array{holder: string, path: string, captured: string}> $captured
     */
    private function reportCapturedState(array $captured): void
    {
        if ($captured === []) {
            return;
        }

        $lines = array_map(
            static fn (array $finding): string =>
                "  {$finding['path']} still holds a {$finding['captured']}",
            $captured,
        );

        throw new CapturedRequestStateException(
            "Request-scoped state outlived its request:\n" . implode("\n", $lines)
            . "\n  Each holder answers every later request from this one's data. "
            . 'Resolve what it needs per call rather than keeping it.'
        );
    }

    /**
     * Ask every resolved service that holds request state to drop it.
     *
     * Only resolved instances are visited: a binding nothing has asked for has
     * no state to clear, and resolving one here would build the whole container
     * on every request to no purpose.
     */
    private function resetStatefulServices(): void
    {
        foreach ($this->container->getResolvedInstances() as $instance) {
            if (! $instance instanceof ResetsBetweenRequests) {
                continue;
            }

            try {
                $instance->resetBetweenRequests();
            } catch (Throwable $exception) {
                // The reset continues: there is a next request to serve either
                // way, and no request in scope here to fail. It is logged
                // rather than swallowed because a service that failed to clear
                // goes on answering later requests from the state of the one
                // that just ended — the same failure CapturedRequestStateException
                // exists to make visible, and undiagnosable without this line.
                Logger::error('Service failed to reset between requests', [
                    'service'   => get_class($instance),
                    'exception' => $exception->getMessage(),
                    'origin'    => $exception->getFile() . ':' . $exception->getLine(),
                ]);
            }
        }
    }
}
