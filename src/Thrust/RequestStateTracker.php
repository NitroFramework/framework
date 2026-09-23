<?php

namespace Nitro\Thrust;

use Nitro\Container\Container;
use SplObjectStorage;

/**
 * Finds request-lived objects that something longer-lived is still holding.
 *
 * A singleton that takes the request in its constructor keeps the first one
 * for the life of the worker and answers every request after it from that
 * one's input. Under php-fpm the process ends with the request and the defect
 * is invisible; under a worker it simply serves the wrong data.
 *
 * Watching costs a reference to every request-lived object for the length of a
 * request, and the scan reflects over the whole long-lived object graph, so it
 * is turned on in a suite or on a developer's worker and left off in
 * production.
 *
 * The container reports what it hands out and knows nothing else about this:
 * which names are short-lived, what has been handed out this request, and what
 * counts as a capture are all decided here.
 */
final class RequestStateTracker
{
    /**
     * Request-lived objects handed out since the last reset, by generation.
     *
     * @var SplObjectStorage<object, int>
     */
    private SplObjectStorage $requestLived;

    /**
     * Longer-lived objects the container has handed out, by the name they were
     * resolved under. These are the possible holders.
     *
     * @var array<string, object>
     */
    private array $longLived = [];

    /** Which request is current, counted rather than named: only equality matters. */
    private int $generation = 0;

    /**
     * Names whose objects live for one request, as a set.
     *
     * @var array<string, true>
     */
    private array $requestScoped;

    /**
     * @param array<int, string> $requestScopedNames Names that live for a single request.
     */
    public function __construct(private Container $container, array $requestScopedNames)
    {
        $this->requestLived = new SplObjectStorage();
        $this->requestScoped = array_fill_keys($requestScopedNames, true);
    }

    /**
     * Start recording what the container hands out.
     *
     * Registered as a resolution observer rather than through the container's
     * resolving() callbacks because instance() does not fire those, and the
     * request — the object most worth watching — arrives that way.
     */
    public function watch(): static
    {
        $this->container->observeResolutions(
            function (string $name, mixed $object): void {
                $this->note($name, $object);
            }
        );

        return $this;
    }

    /** Stop recording and drop everything held. */
    public function stop(): void
    {
        $this->container->observeResolutions(null);

        $this->requestLived = new SplObjectStorage();
        $this->longLived = [];
    }

    /**
     * Note that a request has ended, so objects built during the next one are
     * not mistaken for the ones a scan was looking for.
     */
    public function startNewRequest(): void
    {
        $this->generation++;
        $this->requestLived = new SplObjectStorage();
    }

    /**
     * Request-lived objects still reachable from something that outlives them.
     *
     * Call before dropping the request's instances, or there is nothing left
     * to find.
     *
     * @return array<int, array{holder: string, path: string, captured: string}>
     */
    public function captured(): array
    {
        $opaque = new SplObjectStorage();
        $opaque->attach($this->container);
        $opaque->attach($this);

        return (new CapturedStateScanner($this->requestLived, $this->generation, $opaque))
            ->scan($this->longLived);
    }

    /** File an object the container handed out under $name. */
    private function note(string $name, mixed $object): void
    {
        if (! is_object($object)) {
            return;
        }

        if ($this->livesForOneRequest($name)) {
            $this->requestLived[$object] = $this->generation;

            return;
        }

        $this->longLived[$name] = $object;
    }

    /**
     * Whether $name is one of the short-lived names.
     *
     * An alias is asked about by the name it points at, since the target is
     * what declared how long it lives.
     */
    /**
     * Whether a name belongs to the request rather than to the worker.
     *
     * The names passed in are the ones the reset drops by hand. Everything
     * bound with scoped() is request-lived too, and is asked for rather than
     * listed: SessionGuard is bound under its class name and aliased to Guard
     * and StatefulGuard, none of which appear in that list, so a guard handed
     * out under a contract name was filed as long-lived and then reported for
     * holding the session it is supposed to hold.
     */
    private function livesForOneRequest(string $name): bool
    {
        $target = $this->container->getAlias($name);

        if (isset($this->requestScoped[$name]) || isset($this->requestScoped[$target])) {
            return true;
        }

        $scoped = $this->container->scopedNames();

        return in_array($name, $scoped, true) || in_array($target, $scoped, true);
    }
}
