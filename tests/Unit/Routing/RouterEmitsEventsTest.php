<?php

namespace Tests\Unit\Routing;

use Nitro\Events\Contracts\Dispatcher as DispatcherContract;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Events\Dispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Events\RouteEvent;
use Nitro\Routing\Events\RoutingEvents;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * The router's lifecycle events must actually reach a listener.
 *
 * Router composes DispatchesEvents and calls eventLazy() three times, so
 * route:matched and route:dispatching look like supported hooks. Nothing ever
 * handed the router a dispatcher, so the property stayed null, every emit
 * short-circuited, and the events had never once fired.
 *
 * The emitter is only half a wiring. A layer that can be given a dispatcher
 * has to say so — {@see ReceivesDispatcher} — and something has to give it
 * one, which is the composition root's job.
 */
class RouterEmitsEventsTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    /** A layer that emits events must advertise that it can be handed a bus. */
    public function test_the_router_advertises_that_it_takes_a_dispatcher(): void
    {
        $this->assertInstanceOf(ReceivesDispatcher::class, $this->router());
    }

    public function test_a_matched_route_reaches_a_listener(): void
    {
        $heard = [];

        $events = new Dispatcher();
        $events->listen(RoutingEvents::MATCHED, function (RouteEvent $payload) use (&$heard): void {
            $heard[] = $payload;
        });

        $router = $this->router();
        $router->setDispatcher($events);
        $router->get('/users/{user}', fn () => 'show');

        $router->findMatchingRoute(new Request('GET', '/users/7'));

        $this->assertCount(1, $heard, 'route:matched never fired');
        $this->assertSame('GET', $heard[0]->method);
        $this->assertSame('/users/7', $heard[0]->path);
        $this->assertNull($heard[0]->type, 'nothing has been matched yet at route.matched');
    }

    public function test_dispatching_a_static_route_reaches_a_listener(): void
    {
        $heard = [];

        $events = new Dispatcher();
        $events->listen(RoutingEvents::DISPATCHING, function (RouteEvent $payload) use (&$heard): void {
            $heard[] = $payload;
        });

        $router = $this->router();
        $router->setDispatcher($events);
        $router->get('/about', fn () => 'about');

        $router->findMatchingRoute(new Request('GET', '/about'));

        $this->assertCount(1, $heard, 'route:dispatching never fired for a static route');
        $this->assertSame('static', $heard[0]->strategy, 'how it was matched');
        $this->assertSame('closure', $heard[0]->type, 'what kind of route it is');
    }

    public function test_dispatching_a_dynamic_route_reaches_a_listener(): void
    {
        $heard = [];

        $events = new Dispatcher();
        $events->listen(RoutingEvents::DISPATCHING, function (RouteEvent $payload) use (&$heard): void {
            $heard[] = $payload;
        });

        $router = $this->router();
        $router->setDispatcher($events);
        $router->get('/users/{user}', fn () => 'show');

        $router->findMatchingRoute(new Request('GET', '/users/7'));

        $this->assertCount(1, $heard, 'route:dispatching never fired for a dynamic route');
        $this->assertSame('dynamic', $heard[0]->strategy);
        $this->assertSame('7', $heard[0]->parameters['user'] ?? null, 'the dynamic arm carries bound parameters');
    }

    /** No dispatcher is still valid — the emit costs nothing and nothing breaks. */
    public function test_a_router_with_no_dispatcher_still_matches(): void
    {
        $router = $this->router();
        $router->get('/about', fn () => 'about');

        $this->assertNotNull($router->findMatchingRoute(new Request('GET', '/about')));
    }

    /** The payload is not built when nothing is listening — that is the point of eventLazy. */
    public function test_the_payload_is_not_built_when_nobody_listens(): void
    {
        $events = new class implements DispatcherContract {
            public int $asked = 0;
            public int $dispatched = 0;

            public function listen(string|array $events, callable|string $listener): void {}
            public function subscribe(string|object $subscriber): void {}
            public function forget(string $event): void {}

            public function hasListeners(string $event): bool
            {
                $this->asked++;

                return false;
            }

            public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
            {
                $this->dispatched++;

                return null;
            }

            public function until(string|object $event, mixed $payload = []): mixed
            {
                return null;
            }
        };

        $router = $this->router();
        $router->setDispatcher($events);
        $router->get('/about', fn () => 'about');

        $router->findMatchingRoute(new Request('GET', '/about'));

        $this->assertGreaterThan(0, $events->asked, 'the router should ask before building a payload');
        $this->assertSame(0, $events->dispatched, 'nothing was listening, so nothing should have been dispatched');
    }

    /**
     * The wiring itself: the composition root has to hand the router a bus, or
     * all of the above is theory. Checked at the source because the failure is
     * an absence — nothing errors when it is missing.
     */
    public function test_the_composition_root_wires_the_router(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Foundation/Providers/RoutingServiceProvider.php'
        );

        $this->assertMatchesRegularExpression(
            '/instanceof\s+ReceivesDispatcher/',
            $provider,
            'the routing provider must offer the router a dispatcher',
        );

        $this->assertMatchesRegularExpression(
            '/->setDispatcher\(/',
            $provider,
            'the routing provider must actually hand one over',
        );
    }
}
