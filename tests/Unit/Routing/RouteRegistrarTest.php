<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\RouteRegistrar;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Building a group's attributes fluently instead of as an array literal.
 *
 * Deliberately not by overloading middleware(): that applies to the last
 * registered route and throws when there is none, so
 * Route::middleware('auth')->group(…) written after any route would silently
 * attach the middleware to that route instead of the group. The entry points
 * are named so the two cannot be confused.
 */
class RouteRegistrarTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    private function route(Router $router, string $path, string $method = 'GET'): ?\Nitro\Routing\Route
    {
        return $router->findMatchingRoute(new Request($method, $path));
    }

    public function test_it_starts_from_middleware(): void
    {
        $router = $this->router();

        $this->assertInstanceOf(RouteRegistrar::class, $router->withMiddleware('auth'));
    }

    public function test_it_starts_from_a_prefix(): void
    {
        $router = $this->router();

        $this->assertInstanceOf(RouteRegistrar::class, $router->withPrefix('admin'));
    }

    public function test_a_prefixed_group_registers_under_that_prefix(): void
    {
        $router = $this->router();

        $router->withPrefix('admin')->group(function () use ($router) {
            $router->get('/users', fn () => 'list');
        });

        $this->assertNotNull($this->route($router, '/admin/users'));
        $this->assertNull($this->route($router, '/users'));
    }

    public function test_middleware_reaches_the_routes_inside(): void
    {
        $router = $this->router();

        $router->withMiddleware('auth')->group(function () use ($router) {
            $router->get('/dashboard', fn () => 'home');
        });

        $this->assertContains('auth', $this->route($router, '/dashboard')->getMiddleware());
    }

    public function test_the_parts_chain_in_any_order(): void
    {
        $router = $this->router();

        $router->withPrefix('admin')->middleware('auth')->name('admin.')->group(function () use ($router) {
            $router->get('/users', fn () => 'list')->name('users');
        });

        $route = $this->route($router, '/admin/users');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->getMiddleware());
        $this->assertNotNull($router->getRouteByName('admin.users'));
    }

    public function test_a_group_can_scope_its_bindings_fluently(): void
    {
        $router = $this->router();

        $router->withPrefix('shop')->scopeBindings()->group(function () use ($router) {
            $router->get('/posts/{post}/comments/{comment}', fn () => 'show');
        });

        $this->assertTrue($this->route($router, '/shop/posts/1/comments/2')->isScoped());
    }

    /** Nothing leaks out of the group. */
    public function test_attributes_do_not_survive_the_group(): void
    {
        $router = $this->router();

        $router->withPrefix('admin')->middleware('auth')->group(function () use ($router) {
            $router->get('/users', fn () => 'list');
        });

        $router->get('/public', fn () => 'open');

        $route = $this->route($router, '/public');

        $this->assertNotNull($route, 'a route after the group must not be prefixed');
        $this->assertNotContains('auth', $route->getMiddleware());
    }

    /** The array form still works — this is sugar, not a replacement. */
    public function test_the_array_form_is_unaffected(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'api', 'middleware' => 'throttle'], function () use ($router) {
            $router->get('/ping', fn () => 'pong');
        });

        $this->assertContains('throttle', $this->route($router, '/api/ping')->getMiddleware());
    }

    /** Groups nest. */
    public function test_a_fluent_group_nests_inside_another(): void
    {
        $router = $this->router();

        $router->withPrefix('admin')->group(function () use ($router) {
            $router->withPrefix('reports')->group(function () use ($router) {
                $router->get('/daily', fn () => 'report');
            });
        });

        $this->assertNotNull($this->route($router, '/admin/reports/daily'));
    }
}
