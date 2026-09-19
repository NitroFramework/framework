<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * "{post:slug}" — a route parameter bound by a column of the route's choosing.
 *
 * The placeholder parser took everything between the braces as the parameter
 * name, so "/posts/{post:slug}" registered a parameter literally called
 * "post:slug". A controller's show(Post $post) never saw it, route() could not
 * fill it, and the whole custom-route-key feature was unreachable — along with
 * Model::resolveRouteBinding() and getRouteKeyName(), which existed in full
 * and had no caller anywhere in the framework.
 */
class RouteBindingFieldsTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    public function test_a_custom_key_names_the_parameter_without_the_column(): void
    {
        $router = $this->router();
        $router->get('/posts/{post:slug}', fn () => 'show');

        $route = $router->findMatchingRoute(new Request('GET', '/posts/hello-world'));

        $this->assertNotNull($route);
        $this->assertSame('hello-world', $route->getParameters()['post'] ?? null);
        $this->assertArrayNotHasKey('post:slug', $route->getParameters());
    }

    public function test_the_column_is_carried_on_the_route(): void
    {
        $router = $this->router();
        $router->get('/posts/{post:slug}/comments/{comment}', fn () => 'show');

        $route = $router->findMatchingRoute(new Request('GET', '/posts/hello/comments/3'));

        $this->assertNotNull($route);
        $this->assertSame('slug', $route->getBindingField('post'));
        $this->assertNull($route->getBindingField('comment'), 'a plain parameter names no column');
        $this->assertSame(['post' => 'slug'], $route->getBindingFields());
    }

    /** A route with no custom key carries an empty map, not a map of nulls. */
    public function test_an_ordinary_route_carries_no_binding_fields(): void
    {
        $router = $this->router();
        $router->get('/users/{user}', fn () => 'show');

        $route = $router->findMatchingRoute(new Request('GET', '/users/1'));

        $this->assertSame([], $route->getBindingFields());
    }

    /** Optional and custom-keyed at once: "{post:slug?}". */
    public function test_a_custom_key_can_also_be_optional(): void
    {
        $router = $this->router();
        $router->get('/posts/{post:slug?}', fn () => 'index');

        $bare = $router->findMatchingRoute(new Request('GET', '/posts'));
        $withSlug = $router->findMatchingRoute(new Request('GET', '/posts/hello'));

        $this->assertNotNull($bare, 'the optional segment must still be optional');
        $this->assertNotNull($withSlug);
        $this->assertSame('hello', $withSlug->getParameters()['post'] ?? null);
        $this->assertSame('slug', $withSlug->getBindingField('post'));
    }

    /** URL generation has to know the same name the route binds under. */
    public function test_url_generation_fills_a_custom_keyed_placeholder(): void
    {
        $router = $this->router();
        $router->get('/posts/{post:slug}', fn () => 'show')->name('posts.show');

        $this->assertSame('/posts/hello-world', $router->route('posts.show', ['post' => 'hello-world']));
        $this->assertSame('/posts/hello-world', $router->route('posts.show', ['hello-world']));
    }

    /** An unfilled optional placeholder disappears rather than being an error. */
    public function test_url_generation_drops_an_unfilled_optional_segment(): void
    {
        $router = $this->router();
        $router->get('/posts/{page?}', fn () => 'index')->name('posts.index');

        $this->assertSame('/posts', $router->route('posts.index'));
        $this->assertSame('/posts/2', $router->route('posts.index', ['page' => 2]));
    }

    /** The custom key must survive being written to the route cache and read back. */
    public function test_a_custom_key_survives_the_route_cache(): void
    {
        $router = $this->router();
        $router->get('/posts/{post:slug}', 'PostController@show');

        $warm = $this->router();
        $warm->loadCachedRoutes([
            'routes'            => $router->getRoutes(),
            'named_routes'      => $router->getNamedRoutes(),
            'static_routes'     => $router->getCompiledRoutes()['static'],
            'dynamic_routes'    => $router->getCompiledRoutes()['dynamic'],
            'dynamic_by_prefix' => $router->getCompiledRoutes()['byPrefix'],
            'compiled_patterns' => $router->getCompiledRoutes()['patterns'],
        ]);

        $route = $warm->findMatchingRoute(new Request('GET', '/posts/hello-world'));

        $this->assertNotNull($route, 'a cached custom-keyed route must still match');
        $this->assertSame('slug', $route->getBindingField('post'));
        $this->assertSame('hello-world', $route->getParameters()['post'] ?? null);
    }
}
