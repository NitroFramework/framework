<?php

namespace Tests\Wiring;

use Nitro\Cache\Repository;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Support\Pipeline;

/**
 * The wiring bugs this framework has actually had, asserted directly.
 *
 * The generic tests alongside this one check invariants — everything resolves,
 * lifetimes match what was declared, an alias equals its target. Neither of the
 * two bugs below would have tripped them, and it is worth being exact about why
 * rather than assuming a suite covers more than it does:
 *
 *   - Pipeline was declared shared and behaved shared, so declared and actual
 *     agreed. What was wrong was the intent, which lived in a comment. No
 *     invariant can read that.
 *
 *   - The cache repository's dispatcher was attached inside one of two bindings
 *     that hand out the same memoized object, so the object's collaborators
 *     depended on which binding ran first. The order test compares a service
 *     resolved alone against the same service resolved last, which catches the
 *     shape of it — but only once something has resolved the other binding, and
 *     nothing guarantees the ordering that exposes it.
 *
 * So both get an assertion that names the behaviour. A regression test for a
 * bug you have had is worth more than an invariant that nearly covers it.
 */
class KnownWiringRegressionsTest extends WiringTestCase
{
    /**
     * A pipeline carries the value passing through it, so two callers must
     * never share one.
     *
     * bind() defaults its third argument to a shared binding, so the
     * two-argument call registered a singleton and every caller got the same
     * object — with whatever the previous caller had sent through it.
     */
    public function test_a_pipeline_is_never_shared(): void
    {
        $first  = $this->container->resolve(Pipeline::class);
        $second = $this->container->resolve(Pipeline::class);

        $this->assertNotSame($first, $second, 'a pipeline must be built per caller');

        // And the state really is separate, not just the object identity.
        $first->send('A')->through([]);
        $second->send('B')->through([]);

        $this->assertSame('A', $first->then(static fn (string $passable): string => $passable));
        $this->assertSame('B', $second->then(static fn (string $passable): string => $passable));
    }

    /**
     * A cache repository raises cache.hit/missed/written/forgotten, so it needs
     * the event bus however it was reached.
     *
     * The dispatcher used to be attached by the Repository::class binding.
     * Because CacheManager memoizes stores, that binding and cache.store hand
     * back the same object — so a store reached any other way had no bus until
     * something happened to resolve that one, which in practice meant until the
     * rate limiter was built.
     */
    public function test_a_cache_store_has_its_event_bus_however_it_was_reached(): void
    {
        $routes = [
            "resolve('cache')->store()" => $this->container->resolve('cache')->store(),
            "resolve('cache.store')"    => $this->container->resolve('cache.store'),
            'resolve(Repository::class)' => $this->container->resolve(Repository::class),
        ];

        foreach ($routes as $how => $repository) {
            $this->assertInstanceOf(ReceivesDispatcher::class, $repository, $how);
            $this->assertTrue($this->hasDispatcher($repository), "no event bus via {$how}");
        }
    }

    /** Events fire without anything having resolved a particular binding first. */
    public function test_cache_events_fire_on_a_cold_application(): void
    {
        $app       = $this->freshApplication();
        $container = $app->getContainer();

        $seen = [];

        foreach (['cache.written', 'cache.hit', 'cache.missed', 'cache.forgotten'] as $event) {
            $container->resolve('events')->listen(
                $event,
                static function () use ($event, &$seen): void { $seen[] = $event; },
            );
        }

        // Straight through the manager. Nothing has touched Repository::class.
        $store = $container->resolve('cache')->store();

        $store->put('probe', 'value', 60);
        $store->get('probe');
        $store->get('absent');
        $store->forget('probe');

        $this->assertSame(
            ['cache.written', 'cache.hit', 'cache.missed', 'cache.forgotten'],
            $seen,
            'a store reached through the manager must raise its events',
        );
    }

    private function hasDispatcher(object $repository): bool
    {
        $property = (new \ReflectionObject($repository))->getProperty('dispatcher');
        $property->setAccessible(true);

        return $property->getValue($repository) !== null;
    }
}
