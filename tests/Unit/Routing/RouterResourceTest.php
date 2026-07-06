<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Config;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Route::resource() registers the seven RESTful routes with Laravel's
 * verbs, paths, names, and a singularised wildcard.
 */
class RouterResourceTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(
            fn ($key, $default = null) => $key === 'app.controllers_namespace' ? 'App\\Controllers\\' : $default
        );
        return new Router($config);
    }

    public function test_resource_registers_all_seven_named_routes(): void
    {
        $router = $this->router();
        $router->resource('photos', 'App\\Controllers\\PhotoController');

        $names = array_keys($router->getNamedRoutes());

        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertContains("photos.{$action}", $names, "Missing photos.{$action}");
        }
    }

    public function test_resource_uses_singular_wildcard_and_correct_paths(): void
    {
        $router = $this->router();
        $router->resource('photos', 'App\\Controllers\\PhotoController');

        $this->assertSame('/photos', $router->route('photos.index'));
        $this->assertSame('/photos/create', $router->route('photos.create'));
        $this->assertSame('/photos/5', $router->route('photos.show', ['photo' => 5]));
        $this->assertSame('/photos/5/edit', $router->route('photos.edit', ['photo' => 5]));
    }

    public function test_resource_uses_rest_verbs(): void
    {
        $router = $this->router();
        $router->resource('photos', 'App\\Controllers\\PhotoController');
        $routes = $router->getRoutes();

        $this->assertArrayHasKey('/photos/{photo}', $routes['PUT'] ?? [], 'update should be PUT');
        $this->assertArrayHasKey('/photos/{photo}', $routes['DELETE'] ?? [], 'destroy should be DELETE');
        $this->assertArrayHasKey('/photos', $routes['POST'] ?? [], 'store should be POST');
    }

    public function test_resource_only_option_limits_actions(): void
    {
        $router = $this->router();
        $router->resource('tags', 'App\\Controllers\\TagController', ['only' => ['index', 'show']]);

        $names = array_keys($router->getNamedRoutes());
        $this->assertContains('tags.index', $names);
        $this->assertContains('tags.show', $names);
        $this->assertNotContains('tags.store', $names);
        $this->assertNotContains('tags.destroy', $names);
    }
}
