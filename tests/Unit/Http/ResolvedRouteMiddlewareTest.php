<?php

namespace Tests\Unit\Http;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Application;
use Nitro\Foundation\Http\Kernel;
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

    public function test_gather_prepends_global_and_expands_groups(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', ['GlobalMw']);
        $this->set($kernel, 'middlewareGroups', ['web' => ['CsrfMw', 'SessionMw']]);

        $route = Route::closure(fn() => null, [], ['web', 'auth']);

        $this->assertSame(
            ['GlobalMw', 'CsrfMw', 'SessionMw', 'auth'],
            $this->gather($kernel, $route),
        );
    }

    public function test_gather_passes_unknown_names_through_unchanged(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', []);
        $this->set($kernel, 'middlewareGroups', ['web' => ['CsrfMw']]);

        $route = Route::closure(fn() => null, [], ['App\\Middleware\\Custom']);

        $this->assertSame(['App\\Middleware\\Custom'], $this->gather($kernel, $route));
    }

    public function test_gather_with_no_middleware_returns_only_global(): void
    {
        $kernel = $this->kernel();
        $this->set($kernel, 'middleware', ['GlobalMw']);
        $this->set($kernel, 'middlewareGroups', []);

        $route = Route::closure(fn() => null, [], []);

        $this->assertSame(['GlobalMw'], $this->gather($kernel, $route));
    }
}
