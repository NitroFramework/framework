<?php

namespace Tests\Unit\Events;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Events\CacheEvent;
use Nitro\Cache\Events\CacheEvents;
use Nitro\Cache\Repository;
use Nitro\Database\Events\QueryEvent;
use Nitro\Database\Events\TransactionEvent;
use Nitro\Events\CoreEvents;
use Nitro\Events\Dispatcher;
use Nitro\Exceptions\Events\ExceptionEvent;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Events\ProviderEvent;
use Nitro\Http\Events\RequestEvent;
use Nitro\Http\Request;
use Nitro\Routing\Events\RouteEvent;
use Nitro\Routing\Events\RoutingEvents;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use Nitro\View\Events\ViewEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A listener should be handed an object it can type-hint, not a loose array.
 *
 * ['tiem' => …] instead of ['time' => …] reads as null and is silently never
 * true; $event->tiem is a fatal on the first run. The payload is built inside
 * eventLazy()'s closure, which only runs when something is listening — so a
 * payload class is autoloaded only for an app that actually uses it, and an
 * app with no listeners pays nothing for any of this.
 *
 * The classes live in the layer that raises them, not in a central payload
 * bag, for the same reason the router no longer names Livewire.
 */
class EventPayloadsAreTypedTest extends TestCase
{
    /** Payload class => the layer directory it must live in. */
    private const PAYLOADS = [
        ProviderEvent::class    => 'Foundation/Events',
        RequestEvent::class     => 'Http/Events',
        RouteEvent::class       => 'Routing/Events',
        QueryEvent::class       => 'Database/Events',
        TransactionEvent::class => 'Database/Events',
        ViewEvent::class        => 'View/Events',
        CacheEvent::class       => 'Cache/Events',
        ExceptionEvent::class   => 'Exceptions/Events',
    ];

    public function test_each_payload_lives_in_the_layer_that_raises_it(): void
    {
        foreach (self::PAYLOADS as $class => $directory) {
            $this->assertTrue(class_exists($class), "missing payload class {$class}");

            $file = str_replace('\\', '/', (string) (new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                $directory,
                $file,
                "{$class} should live in src/{$directory}",
            );
        }
    }

    /**
     * A payload describes something that already happened, so nothing should
     * be able to edit it on the way between listeners.
     */
    public function test_every_payload_is_immutable(): void
    {
        foreach (array_keys(self::PAYLOADS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $this->assertTrue(
                    $property->isReadOnly(),
                    $class . '::$' . $property->getName() . ' must be readonly',
                );

                $this->assertTrue(
                    $property->isPublic(),
                    $class . '::$' . $property->getName() . ' must be public — a listener has to read it',
                );
            }
        }
    }

    /** Every property is typed, or the whole exercise is pointless. */
    public function test_every_payload_property_is_typed(): void
    {
        foreach (array_keys(self::PAYLOADS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $this->assertTrue(
                    $property->hasType(),
                    $class . '::$' . $property->getName() . ' has no type',
                );
            }
        }
    }

    // ─── What actually arrives ────────────────────────────────────────────

    public function test_a_cache_lookup_delivers_a_cache_event(): void
    {
        $heard = [];

        $events = new Dispatcher();
        foreach ([CacheEvents::MISSED, CacheEvents::WRITTEN, CacheEvents::HIT] as $event) {
            $events->listen($event, function (CacheEvent $payload) use (&$heard, $event): void {
                $heard[$event] = $payload;
            });
        }

        $cache = new Repository(new ArrayStore());
        $cache->setDispatcher($events);

        $cache->get('missing');
        $cache->put('greeting', 'hello', 60);
        $cache->get('greeting');

        $this->assertInstanceOf(CacheEvent::class, $heard[CacheEvents::MISSED] ?? null);
        $this->assertSame('missing', $heard[CacheEvents::MISSED]->key);

        $this->assertSame('greeting', $heard[CacheEvents::WRITTEN]->key);
        $this->assertSame('hello', $heard[CacheEvents::WRITTEN]->value);
        $this->assertSame(60, $heard[CacheEvents::WRITTEN]->ttl);

        $this->assertSame('hello', $heard[CacheEvents::HIT]->value);
    }

    public function test_a_matched_route_delivers_a_route_event(): void
    {
        $heard = null;

        $events = new Dispatcher();
        $events->listen(RoutingEvents::MATCHED, function (RouteEvent $payload) use (&$heard): void {
            $heard = $payload;
        });

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        $router = new Router($config, new RouteTypes());
        $router->setDispatcher($events);
        $router->get('/users/{user}', fn () => 'show');

        $router->findMatchingRoute(new Request('GET', '/users/7'));

        $this->assertInstanceOf(RouteEvent::class, $heard);
        $this->assertSame('GET', $heard->method);
        $this->assertSame('/users/7', $heard->path);
    }

    /** A payload cannot be edited between listeners. */
    public function test_a_payload_cannot_be_mutated_by_a_listener(): void
    {
        $payload = new CacheEvent('key', 'value', 60);

        $this->expectException(\Error::class);

        /** @phpstan-ignore-next-line writing to a readonly property is the point */
        $payload->key = 'something-else';
    }

    /** Optional fields are genuinely optional, so a paired event can share a class. */
    public function test_a_payload_may_omit_the_fields_its_event_does_not_have(): void
    {
        $executing = new QueryEvent('select 1', []);
        $executed = new QueryEvent('select 1', [], 12.5);

        $this->assertNull($executing->time, 'query.executing has no elapsed time yet');
        $this->assertSame(12.5, $executed->time);
    }

    public function test_the_payload_classes_carry_no_behaviour(): void
    {
        foreach (array_keys(self::PAYLOADS) as $class) {
            $methods = array_filter(
                (new ReflectionClass($class))->getMethods(),
                static fn ($m): bool => $m->getName() !== '__construct' && $m->class === $class,
            );

            $this->assertSame(
                [],
                array_map(static fn ($m): string => $m->getName(), $methods),
                "{$class} should be data, not a service",
            );
        }
    }
}
