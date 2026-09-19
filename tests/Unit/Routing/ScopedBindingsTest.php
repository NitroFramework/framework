<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Nested route parameters, soft-deleted models, and a per-route 404.
 *
 * /posts/{post}/comments/{comment} currently returns comment 99 whatever post
 * it belongs to. scopeBindings() makes the child resolve through the parent's
 * relation, so a comment from another post is a 404 rather than someone
 * else's data.
 *
 * Model::resolveChildRouteBinding() already existed with no caller.
 */
class ScopedBindingsTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    private function match(Router $router, string $path): ?\Nitro\Routing\Route
    {
        return $router->findMatchingRoute(new Request('GET', $path));
    }

    // ─── scopeBindings ────────────────────────────────────────────────────

    public function test_a_route_is_not_scoped_by_default(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}/comments/{comment}', fn () => 'show');

        $this->assertFalse($this->match($router, '/posts/1/comments/2')->isScoped());
    }

    public function test_scope_bindings_marks_the_route(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}/comments/{comment}', fn () => 'show')->scopeBindings();

        $this->assertTrue($this->match($router, '/posts/1/comments/2')->isScoped());
    }

    /** The flag has to survive the route cache, or it only works in dev. */
    public function test_scoping_survives_the_route_cache(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}/comments/{comment}', 'PostController@show')->scopeBindings();

        $warm = $this->router();
        $warm->loadCachedRoutes([
            'routes'            => $router->getRoutes(),
            'named_routes'      => $router->getNamedRoutes(),
            'static_routes'     => $router->getCompiledRoutes()['static'],
            'dynamic_routes'    => $router->getCompiledRoutes()['dynamic'],
            'dynamic_by_prefix' => $router->getCompiledRoutes()['byPrefix'],
            'compiled_patterns' => $router->getCompiledRoutes()['patterns'],
        ]);

        $this->assertTrue($this->match($warm, '/posts/1/comments/2')->isScoped());
    }

    /** A group can scope every route inside it. */
    public function test_a_group_can_scope_its_routes(): void
    {
        $router = $this->router();
        $router->group(['scopeBindings' => true], function () use ($router) {
            $router->get('/posts/{post}/comments/{comment}', fn () => 'show');
        });

        $this->assertTrue($this->match($router, '/posts/1/comments/2')->isScoped());
    }

    /** The parameter before this one is what a child resolves through. */
    public function test_the_route_names_the_parameter_a_child_scopes_to(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}/comments/{comment}', fn () => 'show')->scopeBindings();

        $route = $this->match($router, '/posts/1/comments/2');

        $this->assertNull($route->parentParameter('post'), 'the first parameter has no parent');
        $this->assertSame('post', $route->parentParameter('comment'));
    }

    // ─── withTrashed ──────────────────────────────────────────────────────

    public function test_a_route_excludes_soft_deleted_models_by_default(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', fn () => 'show');

        $this->assertFalse($this->match($router, '/posts/1')->includesTrashed());
    }

    public function test_with_trashed_marks_the_route(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', fn () => 'show')->withTrashed();

        $this->assertTrue($this->match($router, '/posts/1')->includesTrashed());
    }

    public function test_with_trashed_survives_the_route_cache(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', 'PostController@show')->withTrashed();

        $warm = $this->router();
        $warm->loadCachedRoutes([
            'routes'            => $router->getRoutes(),
            'named_routes'      => $router->getNamedRoutes(),
            'static_routes'     => $router->getCompiledRoutes()['static'],
            'dynamic_routes'    => $router->getCompiledRoutes()['dynamic'],
            'dynamic_by_prefix' => $router->getCompiledRoutes()['byPrefix'],
            'compiled_patterns' => $router->getCompiledRoutes()['patterns'],
        ]);

        $this->assertTrue($this->match($warm, '/posts/1')->includesTrashed());
    }

    // ─── missing ──────────────────────────────────────────────────────────

    public function test_a_route_has_no_missing_handler_by_default(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', fn () => 'show');

        $this->assertNull($this->match($router, '/posts/1')->missingHandler());
    }

    public function test_missing_registers_a_handler(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', fn () => 'show')
               ->missing(fn () => 'gone');

        $handler = $this->match($router, '/posts/1')->missingHandler();

        $this->assertIsCallable($handler);
        $this->assertSame('gone', $handler());
    }

    /**
     * A closure cannot be written to the route cache, so a route carrying one
     * must be refused the same way a closure handler is — quietly caching a
     * route whose handler is gone would be worse than not caching at all.
     */
    public function test_a_missing_handler_blocks_route_caching(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', 'PostController@show')->missing(fn () => 'gone');

        $cached = $router->getRoutes();

        $this->assertTrue(
            $this->containsClosure($cached),
            'the closure must be visible to the cache writer so it refuses',
        );
    }

    private function containsClosure(mixed $value): bool
    {
        if ($value instanceof \Closure) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsClosure($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
