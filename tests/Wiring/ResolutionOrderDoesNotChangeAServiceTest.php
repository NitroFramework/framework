<?php

namespace Tests\Wiring;

use ReflectionObject;

/**
 * What you get from the container must not depend on what was asked for first.
 *
 * The cache layer attached its event dispatcher inside one of the two bindings
 * that hand out the default store. Because the manager memoizes stores, both
 * bindings return the same object — so a store resolved on its own had no bus
 * and silently gained one later, once something happened to resolve the other
 * binding. In practice that was the rate limiter, so whether cache events fired
 * depended on whether a route had used the throttle middleware.
 *
 * Every test in the suite passed. The class was right; the wiring was ordered.
 */
class ResolutionOrderDoesNotChangeAServiceTest extends WiringTestCase
{
    /**
     * Services whose state legitimately differs by the time the app is warm.
     *
     * @var array<string, string> name => why
     */
    private const ORDER_DEPENDENT_BY_DESIGN = [
        'events'                        => 'listeners accumulate as providers boot',
        'Nitro\Events\Dispatcher'       => 'as above',
        'Nitro\Events\Contracts\Dispatcher' => 'as above',
        'router'                        => 'routes accumulate as route files load',
        'Nitro\Routing\Router'          => 'as above',
        'Nitro\Routing\Contracts\RouterInterface' => 'as above',
        'Nitro\Routing\RouteTypes'      => 'feature layers add types during register()',
        'Nitro\Routing\RouteLoader'     => 'route files are queued by providers',
        'Nitro\Http\Kernel'             => 'middleware and lifecycle hooks accumulate',
        'Nitro\Console\CommandManager'  => 'commands are discovered on construction',
        'Nitro\Foundation\Providers\ServiceProvider' => 'not a service',
    ];

    /**
     * Resolve a service into a cold application, then into a warm one, and
     * compare what the object is holding.
     */
    public function test_a_service_is_the_same_whether_resolved_first_or_last(): void
    {
        $names = array_filter(
            $this->everyServiceName(),
            fn (string $name): bool => ! $this->isSkipped($name)
                && ! isset(self::ORDER_DEPENDENT_BY_DESIGN[$name]),
        );

        $failures = [];

        foreach ($names as $name) {
            $cold = $this->fingerprintInIsolation($name);

            if ($cold === null) {
                continue;
            }

            $warm = $this->fingerprintWhenWarm($name);

            if ($warm !== null && $cold !== $warm) {
                $failures[] = sprintf(
                    "%s\n    resolved alone : %s\n    resolved last  : %s",
                    $name,
                    $cold,
                    $warm,
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these services depend on what was resolved before them:\n\n" . implode("\n\n", $failures),
        );
    }

    /** Resolve into a fresh application that has been asked for nothing else. */
    private function fingerprintInIsolation(string $name): ?string
    {
        $app = $this->freshApplication();

        try {
            return $this->fingerprint($app->getContainer()->resolve($name));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Resolve after everything else has been resolved. */
    private function fingerprintWhenWarm(string $name): ?string
    {
        $app       = $this->freshApplication();
        $container = $app->getContainer();

        foreach ($this->everyServiceName() as $other) {
            if ($other === $name || $this->isSkipped($other)) {
                continue;
            }

            try {
                $container->resolve($other);
            } catch (\Throwable) {
                // A service that cannot resolve is the other test's problem.
            }
        }

        try {
            return $this->fingerprint($container->resolve($name));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Which collaborators an object is holding.
     *
     * Only properties that are an object or null, and only their type — never
     * a value, a count or a scalar. Arrays and scalars are where a service
     * keeps its caches, registries and counters, and those legitimately grow
     * as an application warms up: a view finder gains a namespace when a later
     * provider registers one, a cache manager memoizes the store it just
     * built. Flagging those would bury the signal.
     *
     * What is left is the thing that went wrong: a collaborator that is null
     * when the service is resolved on its own and an object when it is
     * resolved last, because something else wired it in passing.
     */
    private function fingerprint(mixed $service): string
    {
        if (! is_object($service)) {
            return get_debug_type($service);
        }

        $parts = [];

        foreach ((new ReflectionObject($service))->getProperties() as $property) {
            $property->setAccessible(true);

            if (! $property->isInitialized($service)) {
                continue;
            }

            $value = $property->getValue($service);

            if ($value !== null && ! is_object($value)) {
                continue;
            }

            $parts[] = $property->getName() . '=' . ($value === null ? 'null' : $value::class);
        }

        sort($parts);

        return $service::class . '{' . implode(', ', $parts) . '}';
    }
}
