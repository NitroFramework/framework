<?php

namespace Tests\Unit\Routing;

use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Middleware put on a route goes on that route.
 *
 * It used to append to the group's stack instead, so a single
 * ->middleware('auth') locked every route declared below it for the rest of the
 * file. The symptom is a public page redirecting to the login screen with
 * nothing near it to explain why, and it is invisible in review: the line that
 * causes it looks exactly like the Laravel idiom it is copying.
 */
class RouteMiddlewareScopeTest extends TestCase
{
    private function router(): Router
    {
        $config = new class implements \Nitro\Foundation\Contracts\ConfigRepository {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'app.controllers_namespace' ? 'App\\Controllers' : $default;
            }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        return new Router($config);
    }

    /** @return array<int, string> */
    private function middlewareOf(Router $router, string $path, string $method = 'GET'): array
    {
        return $router->getRoutes()[$method][$path]['middleware'] ?? [];
    }

    public function test_middleware_lands_on_the_route_it_follows(): void
    {
        $router = $this->router();

        $router->get('/invoice', fn () => 'invoice')->middleware('auth');

        $this->assertSame(['auth'], $this->middlewareOf($router, '/invoice'));
    }

    public function test_it_does_not_reach_the_next_route(): void
    {
        $router = $this->router();

        $router->get('/invoice', fn () => 'invoice')->middleware('auth');
        $router->get('/terms', fn () => 'terms');

        // The whole point. A public page declared after a private one stays
        // public.
        $this->assertSame([], $this->middlewareOf($router, '/terms'));
    }

    public function test_it_survives_being_named_afterwards(): void
    {
        $router = $this->router();

        $router->get('/invoice', fn () => 'invoice')->middleware('auth')->name('invoice');

        $this->assertSame(['auth'], $this->middlewareOf($router, '/invoice'));
    }

    public function test_it_can_be_named_before_the_middleware(): void
    {
        $router = $this->router();

        $router->get('/invoice', fn () => 'invoice')->name('invoice')->middleware('auth');

        $this->assertSame(['auth'], $this->middlewareOf($router, '/invoice'));
    }

    public function test_several_can_be_stacked(): void
    {
        $router = $this->router();

        $router->get('/admin', fn () => 'admin')
            ->middleware(['auth', 'verified'])
            ->middleware('platform');

        $this->assertSame(['auth', 'verified', 'platform'], $this->middlewareOf($router, '/admin'));
    }

    public function test_it_is_added_to_what_the_group_already_gave_the_route(): void
    {
        $router = $this->router();

        $router->group(['middleware' => 'web'], function (Router $router) {
            $router->get('/admin', fn () => 'admin')->middleware('auth');
            $router->get('/home', fn () => 'home');
        });

        $this->assertSame(['web', 'auth'], $this->middlewareOf($router, '/admin'));
        $this->assertSame(['web'], $this->middlewareOf($router, '/home'));
    }

    public function test_a_dynamic_route_keeps_it_in_every_copy(): void
    {
        $router = $this->router();

        $router->get('/orders/{reference}', fn () => 'order')->middleware('auth');

        // A dynamic route is stored three times over — the unified map, the
        // flat list, and the prefix buckets matching actually scans. Losing it
        // from one copy is a route that is guarded in a test and open in a
        // request.
        $this->assertSame(['auth'], $this->middlewareOf($router, '/orders/{reference}'));

        $reflection = new \ReflectionClass($router);

        $buckets = $reflection->getProperty('dynamicRoutesByPrefix');
        $buckets->setAccessible(true);
        $bucketed = $buckets->getValue($router)['GET'] ?? [];

        $found = false;

        foreach ($bucketed as $bucket) {
            foreach ($bucket as $route) {
                if ($route['pattern'] === '/orders/{reference}') {
                    $found = true;
                    $this->assertSame(['auth'], $route['handler']['middleware'] ?? []);
                }
            }
        }

        $this->assertTrue($found, 'the route should be in a prefix bucket');
    }

    public function test_middleware_before_any_route_is_refused(): void
    {
        // Silently becoming group middleware is how the leak happened. Group
        // middleware goes in the group's attributes.
        $this->expectException(RuntimeException::class);

        $this->router()->middleware('auth');
    }
}
