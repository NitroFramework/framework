<?php

namespace Nitro\Container;

use ReflectionObject;
use ReflectionProperty;
use SplObjectStorage;
use Throwable;

/**
 * Finds request-scoped objects still held by something that outlives them.
 *
 * The container's resolution check catches a capture declared in a constructor.
 * It cannot see one made later: a singleton that takes nothing, calls
 * auth()->user() inside a method and keeps the answer in a property has
 * captured the request just as thoroughly, and nothing about its wiring says
 * so. This walks the object graph at the moment the request ends and reports
 * what is still reachable.
 *
 * Reflection over every long-lived object is far too expensive for production.
 * It is for the test suite and for a developer's worker, where one pass per
 * request buys the only view of this failure there is.
 */
class CapturedStateScanner
{
    /**
     * How far into an object's own references to look.
     *
     * A capture is nearly always a property on the holder or one step behind a
     * collaborator it owns. Past that the walk costs more than it finds, and a
     * long chain into framework internals reports a path nobody can act on.
     */
    private const MAX_DEPTH = 3;

    /**
     * @param SplObjectStorage<object, int>   $requestScoped Request-lived objects, by the generation they were built in.
     * @param int                             $generation    The request that is ending.
     * @param SplObjectStorage<object, mixed> $opaque        Objects never walked into or reported through.
     */
    public function __construct(
        private SplObjectStorage $requestScoped,
        private int $generation,
        private SplObjectStorage $opaque,
    ) {}

    /**
     * @param  array<string, mixed> $longLived Resolved instances that outlive a request, keyed by binding name.
     * @return array<int, array{holder: string, path: string, captured: string}>
     */
    public function scan(array $longLived): array
    {
        $findings = [];

        foreach ($longLived as $name => $instance) {
            if (! is_object($instance) || $this->opaque->contains($instance)) {
                continue;
            }

            // Per holder, so that two singletons holding the same request
            // object are two findings rather than one.
            $seen = new SplObjectStorage();

            $this->walk($instance, $name, (string) $name, 0, $seen, $findings);
        }

        return $findings;
    }

    /**
     * @param SplObjectStorage<object, mixed>                                $seen
     * @param array<int, array{holder: string, path: string, captured: string}> $findings
     */
    private function walk(
        object $subject,
        string $holder,
        string $path,
        int $depth,
        SplObjectStorage $seen,
        array &$findings,
    ): void {
        // The container is the registry of everything resolved, so walking into
        // it finds every request-scoped object in the application and calls the
        // container their holder. That is what it is for, not a capture — and
        // it reaches the container from any angle, since the Application, the
        // interface binding and the class binding all point back at it.
        if ($depth > self::MAX_DEPTH || $seen->contains($subject) || $this->opaque->contains($subject)) {
            return;
        }

        $seen->attach($subject);

        foreach ($this->propertiesOf($subject) as $property) {
            $value = $this->valueOf($property, $subject);

            if ($value === null) {
                continue;
            }

            $this->inspect($value, $holder, $path . '->' . $property->getName(), $depth, $seen, $findings);
        }
    }

    /**
     * @param SplObjectStorage<object, mixed>                                $seen
     * @param array<int, array{holder: string, path: string, captured: string}> $findings
     */
    private function inspect(
        mixed $value,
        string $holder,
        string $path,
        int $depth,
        SplObjectStorage $seen,
        array &$findings,
    ): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->inspect($item, $holder, $path . "[{$key}]", $depth, $seen, $findings);
            }

            return;
        }

        if (! is_object($value)) {
            return;
        }

        if ($this->requestScoped->contains($value) && $this->requestScoped[$value] === $this->generation) {
            $findings[] = [
                'holder' => $holder,
                'path' => $path,
                'captured' => $value::class,
            ];

            // Report the holder and stop: everything below a captured object is
            // captured too, and listing it is noise around the one fact.
            return;
        }

        $this->walk($value, $holder, $path, $depth + 1, $seen, $findings);
    }

    /** @return array<int, ReflectionProperty> */
    private function propertiesOf(object $subject): array
    {
        try {
            return (new ReflectionObject($subject))->getProperties();
        } catch (Throwable) {
            return [];
        }
    }

    private function valueOf(ReflectionProperty $property, object $subject): mixed
    {
        if ($property->isStatic()) {
            return null;
        }

        try {
            // A typed property declared but never assigned throws on read.
            if (! $property->isInitialized($subject)) {
                return null;
            }

            return $property->getValue($subject);
        } catch (Throwable) {
            return null;
        }
    }
}
