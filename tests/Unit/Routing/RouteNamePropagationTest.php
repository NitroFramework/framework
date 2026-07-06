<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Config;
use Nitro\Routing\Route;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A named dynamic route must carry its name in EVERY lookup structure —
 * especially the prefix buckets that matching actually reads. The old name()
 * only updated the flat dynamic list, so matched dynamic routes came back with
 * a null name in dev/uncached mode. The cache must persist/restore the buckets
 * too, so production matches the same way.
 */
class RouteNamePropagationTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');
        return new Router($config);
    }

    private function findDynamic(Router $r, string $method, string $path): ?array
    {
        return (new ReflectionMethod(Router::class, 'findDynamicRoute'))->invoke($r, $method, $path);
    }

    private function createRoute(Router $r, array $handler, array $params): Route
    {
        return (new ReflectionMethod(Router::class, 'createRoute'))->invoke($r, $handler, $params);
    }

    public function test_named_dynamic_route_carries_name_through_matching(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => null)->name('users.show');

        $match = $this->findDynamic($r, 'GET', '/users/42');
        $this->assertNotNull($match);
        $this->assertSame('users.show', $match['handler']['name']);

        // And the resolved Route value object exposes it.
        $route = $this->createRoute($r, $match['handler'], $match['parameters']);
        $this->assertSame('users.show', $route->getName());
    }

    public function test_compiled_routes_include_named_prefix_buckets(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => null)->name('users.show');

        $compiled = $r->getCompiledRoutes();
        $this->assertArrayHasKey('byPrefix', $compiled);
        $this->assertSame(
            'users.show',
            $compiled['byPrefix']['GET']['users'][0]['handler']['name'],
        );
    }

    public function test_cache_round_trip_restores_named_buckets(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => null)->name('users.show');
        $compiled = $r->getCompiledRoutes();

        $restored = $this->router();
        $restored->loadCachedRoutes([
            'routes'            => $r->getRoutes(),
            'named_routes'      => $r->getNamedRoutes(),
            'static_routes'     => $compiled['static'],
            'dynamic_routes'    => $compiled['dynamic'],
            'dynamic_by_prefix' => $compiled['byPrefix'],
            'compiled_patterns' => $compiled['patterns'],
        ]);

        $match = $this->findDynamic($restored, 'GET', '/users/42');
        $this->assertNotNull($match);
        $this->assertSame('users.show', $match['handler']['name']);
    }

    public function test_legacy_cache_without_buckets_rebuilds_them(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => null)->name('users.show');
        $compiled = $r->getCompiledRoutes();

        // Simulate a cache predating the persisted buckets: omit dynamic_by_prefix.
        $restored = $this->router();
        $restored->loadCachedRoutes([
            'routes'            => $r->getRoutes(),
            'named_routes'      => $r->getNamedRoutes(),
            'static_routes'     => $compiled['static'],
            'dynamic_routes'    => $compiled['dynamic'],
            'compiled_patterns' => $compiled['patterns'],
        ]);

        $match = $this->findDynamic($restored, 'GET', '/users/42');
        $this->assertNotNull($match);
        $this->assertSame('users.show', $match['handler']['name']);
    }
}
