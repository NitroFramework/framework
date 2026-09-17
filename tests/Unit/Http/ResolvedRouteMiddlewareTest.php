<?php

namespace Tests\Unit\Http;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Middleware gathering now lives on the Kernel (it needs the global stack and
 * the group map): global middleware runs first, then each route middleware —
 * with group names like 'web' expanded into their members. The Route itself
 * just carries its declared middleware in original order.
 */
class ResolvedRouteMiddlewareTest extends TestCase
{
    private function kernel(): Kernel
    {
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($this->createMock(ContainerInterface::class));

        return new Kernel(
            $app,
            $this->createMock(Router::class),
            $this->createMock(RouteDispatcher::class),
        );
    }

    private function gather(Kernel $kernel, Route $route): array
    {
        return (new ReflectionMethod(Kernel::class, 'gatherMiddleware'))->invoke($kernel, $route);
    }

    private function set(Kernel $kernel, string $prop, array $value): void
    {
        (new ReflectionProperty(Kernel::class, $prop))->setValue($kernel, $value);
    }

    public function test_route_keeps_declared_middleware_in_original_order(): void
    {
        $route = Route::closure(fn() => null, [], ['auth', 'throttle', 'verified']);
        $this->assertSame(['auth', 'throttle', 'verified'], $route->getMiddleware());
    }

    public function test_gather_expands_groups_in_place(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middlewareGroups', ['web' => ['CsrfMw', 'SessionMw']]);

        $route = Route::closure(fn() => null, [], ['web', 'auth']);

        $this->assertSame(
            ['CsrfMw', 'SessionMw', 'auth'],
            $this->gather($kernel, $route),
        );
    }

    public function test_gather_excludes_the_global_stack(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', ['GlobalMw']);
        $this->set($kernel, 'middlewareGroups', ['web' => ['CsrfMw']]);

        $route = Route::closure(fn() => null, [], ['web']);

        // The global stack wraps route matching rather than being prepended
        // here, so that it also covers requests that match no route at all.
        // GlobalMiddlewareScopeTest asserts the running order that results.
        $this->assertSame(['CsrfMw'], $this->gather($kernel, $route));
    }

    public function test_gather_passes_unknown_names_through_unchanged(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', []);
        $this->set($kernel, 'middlewareGroups', ['web' => ['CsrfMw']]);

        $route = Route::closure(fn() => null, [], ['App\\Middleware\\Custom']);

        $this->assertSame(['App\\Middleware\\Custom'], $this->gather($kernel, $route));
    }

    public function test_gather_on_a_route_with_no_middleware_is_empty(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', ['GlobalMw']);
        $this->set($kernel, 'middlewareGroups', []);

        $route = Route::closure(fn() => null, [], []);

        $this->assertSame([], $this->gather($kernel, $route));
    }

    public function test_redefining_a_group_is_visible_to_a_route_already_gathered(): void
    {
        $kernel = $this->kernel();
        $kernel->middlewareGroup('web', ['CsrfMw']);

        $route = Route::closure(fn() => null, [], ['web']);
        $this->assertSame(['CsrfMw'], $this->gather($kernel, $route));

        $kernel->middlewareGroup('web', ['CsrfMw', 'SessionMw']);

        // The gathered list is memoized by the route's declared middleware,
        // which has not changed — so redefining the group has to invalidate it.
        // The kernel is a singleton, so a stale entry would outlive the change
        // for the life of a Thrust worker rather than one request.
        $this->assertSame(['CsrfMw', 'SessionMw'], $this->gather($kernel, $route));
    }

    public function test_pushed_global_middleware_is_not_duplicated(): void
    {
        $kernel = $this->kernel();
        $kernel->pushMiddleware('TrustProxies');
        $kernel->pushMiddleware('TrustProxies');

        $this->assertSame(['TrustProxies'], $kernel->getMiddleware());
    }

    public function test_prepend_puts_middleware_ahead_of_the_existing_stack(): void
    {
        $kernel = $this->kernel();
        $kernel->pushMiddleware(['Cors', 'Maintenance']);
        $kernel->prependMiddleware('TrustProxies');

        $this->assertSame(['TrustProxies', 'Cors', 'Maintenance'], $kernel->getMiddleware());
    }
}
