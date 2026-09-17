<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Parameter constraints, optional parameters, literal escaping, and the
 * router/route introspection surface.
 */
class RouteConstraintsTest extends TestCase
{
    private function router(): Router
    {
        $config = new class implements ConfigRepository {
            public function has(string $key): bool { return false; }
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'app.controllers_namespace' ? '' : $default;
            }
            public function all(): array { return []; }
            public function set(string $key, mixed $value): void {}
        };

        return new Router($config);
    }

    private function match(Router $router, string $path, string $method = 'GET'): ?\Nitro\Routing\Route
    {
        return $router->findMatchingRoute(new Request($method, $path));
    }

    // ─── Constraints ──────────────────────────────────────

    public function test_where_number_restricts_to_digits(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', fn () => 'ok')->whereNumber('id');

        $this->assertNotNull($this->match($router, '/posts/42'));
        $this->assertNull($this->match($router, '/posts/abc'));
    }

    /** Constraints let two routes share a shape and be told apart. */
    public function test_constraints_disambiguate_overlapping_routes(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', fn () => 'by-id')->whereNumber('id');
        $router->get('/posts/{slug}', fn () => 'by-slug')->whereAlpha('slug');

        $this->assertSame('42', $this->match($router, '/posts/42')->getParameter('id'));
        $this->assertSame('hello', $this->match($router, '/posts/hello')->getParameter('slug'));
        $this->assertNull($this->match($router, '/posts/he-llo'));
    }

    public function test_where_alpha_numeric(): void
    {
        $router = $this->router();
        $router->get('/x/{code}', fn () => 'ok')->whereAlphaNumeric('code');

        $this->assertNotNull($this->match($router, '/x/abc123'));
        $this->assertNull($this->match($router, '/x/abc-123'));
    }

    public function test_where_uuid(): void
    {
        $router = $this->router();
        $router->get('/u/{id}', fn () => 'ok')->whereUuid('id');

        $this->assertNotNull($this->match($router, '/u/3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertNull($this->match($router, '/u/not-a-uuid'));
    }

    public function test_where_ulid(): void
    {
        $router = $this->router();
        $router->get('/u/{id}', fn () => 'ok')->whereUlid('id');

        $this->assertNotNull($this->match($router, '/u/01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertNull($this->match($router, '/u/short'));
    }

    public function test_where_in_limits_to_a_fixed_set(): void
    {
        $router = $this->router();
        $router->get('/type/{kind}', fn () => 'ok')->whereIn('kind', ['news', 'blog']);

        $this->assertNotNull($this->match($router, '/type/news'));
        $this->assertNotNull($this->match($router, '/type/blog'));
        $this->assertNull($this->match($router, '/type/other'));
    }

    /** An alternation must not escape its group and swallow the rest of the path. */
    public function test_where_in_alternation_cannot_escape_its_group(): void
    {
        $router = $this->router();
        $router->get('/type/{kind}/edit', fn () => 'ok')->whereIn('kind', ['news', 'blog']);

        $this->assertNotNull($this->match($router, '/type/news/edit'));
        $this->assertNull($this->match($router, '/type/news'));
    }

    public function test_where_accepts_an_array(): void
    {
        $router = $this->router();
        $router->get('/{a}/{b}', fn () => 'ok')->where(['a' => '[0-9]+', 'b' => '[a-z]+']);

        $this->assertNotNull($this->match($router, '/12/ab'));
        $this->assertNull($this->match($router, '/ab/12'));
    }

    public function test_where_without_a_route_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->router()->where('id', '[0-9]+');
    }

    // ─── Global patterns ──────────────────────────────────

    public function test_pattern_applies_to_every_route(): void
    {
        $router = $this->router();
        $router->get('/a/{id}', fn () => 'a');
        $router->pattern('id', '[0-9]+');
        $router->get('/b/{id}', fn () => 'b');

        // Applies to the route registered before the pattern was declared…
        $this->assertNotNull($this->match($router, '/a/1'));
        $this->assertNull($this->match($router, '/a/xx'));

        // …and after.
        $this->assertNotNull($this->match($router, '/b/2'));
        $this->assertNull($this->match($router, '/b/yy'));
    }

    public function test_route_constraint_overrides_a_global_pattern(): void
    {
        $router = $this->router();
        $router->pattern('id', '[0-9]+');
        $router->get('/x/{id}', fn () => 'ok')->whereAlpha('id');

        $this->assertNotNull($this->match($router, '/x/abc'));
        $this->assertNull($this->match($router, '/x/123'));
    }

    // ─── Optional parameters ──────────────────────────────

    public function test_optional_parameter_matches_with_and_without(): void
    {
        $router = $this->router();
        $router->get('/archive/{year}/{month?}', fn () => 'ok');

        $with = $this->match($router, '/archive/2026/09');
        $this->assertNotNull($with);
        $this->assertSame('09', $with->getParameter('month'));

        $this->assertNotNull($this->match($router, '/archive/2026'));
    }

    // ─── Literal escaping ─────────────────────────────────

    /** A dot in a route is a dot, not "any character". */
    public function test_literal_metacharacters_are_escaped(): void
    {
        $router = $this->router();
        $router->get('/files/{name}.pdf', fn () => 'ok');

        $this->assertNotNull($this->match($router, '/files/report.pdf'));
        $this->assertNull($this->match($router, '/files/reportXpdf'));
    }

    // ─── Router introspection ─────────────────────────────

    public function test_current_route_tracking(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', fn () => 'ok')->name('posts.show');

        $this->assertNull($router->current());

        $this->match($router, '/posts/7');

        $this->assertNotNull($router->current());
        $this->assertSame('posts.show', $router->currentRouteName());
        $this->assertTrue($router->currentRouteNamed('posts.*'));
        $this->assertTrue($router->is('posts.show'));
        $this->assertFalse($router->is('users.*'));
    }

    /** A miss must not leave the previous request's route answering current(). */
    public function test_current_is_cleared_on_a_miss(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', fn () => 'ok')->name('posts.show');

        $this->match($router, '/posts/7');
        $this->assertSame('posts.show', $router->currentRouteName());

        $this->match($router, '/nothing-here');
        $this->assertNull($router->current());
        $this->assertNull($router->currentRouteName());
    }

    public function test_has_named_route(): void
    {
        $router = $this->router();
        $router->get('/a', fn () => 'a')->name('alpha');

        $this->assertTrue($router->has('alpha'));
        $this->assertFalse($router->has('beta'));
        $this->assertFalse($router->has('alpha', 'beta'));
    }

    // ─── Resource variants ────────────────────────────────

    public function test_api_resource_omits_the_form_routes(): void
    {
        $router = $this->router();
        $router->apiResource('photos', 'PhotoController');

        $this->assertNotNull($this->match($router, '/photos'));
        $this->assertNotNull($this->match($router, '/photos/1'));

        $this->assertTrue($router->has('photos.index'));
        $this->assertTrue($router->has('photos.show'));
        $this->assertFalse($router->has('photos.create'));
        $this->assertFalse($router->has('photos.edit'));

        // /photos/create now falls through to show, as {photo} accepts it.
        $this->assertNull($this->match($router, '/photos/1/edit'));
    }

    public function test_resources_registers_several(): void
    {
        $router = $this->router();
        $router->resources([
            'photos' => 'PhotoController',
            'videos' => 'VideoController',
        ]);

        $this->assertNotNull($this->match($router, '/photos'));
        $this->assertNotNull($this->match($router, '/videos'));
    }

    // ─── Route accessors ──────────────────────────────────

    public function test_route_parameter_accessors(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}/comments/{comment}', fn () => 'ok')->name('c.show');

        $route = $this->match($router, '/posts/7/comments/3');

        $this->assertSame(['id' => '7', 'comment' => '3'], $route->parameters());
        $this->assertSame('7', $route->parameter('id'));
        $this->assertTrue($route->hasParameter('id'));
        $this->assertFalse($route->hasParameter('nope'));
        $this->assertTrue($route->hasParameters());
        $this->assertEqualsCanonicalizing(['id', 'comment'], $route->parameterNames());
        $this->assertSame('c.show', $route->name());
        $this->assertTrue($route->named('c.*'));
        $this->assertFalse($route->named('d.*'));
    }

    public function test_forget_and_set_parameter(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', fn () => 'ok');

        $route = $this->match($router, '/posts/7');

        $route->setParameter('id', '9');
        $this->assertSame('9', $route->parameter('id'));

        $route->forgetParameter('id');
        $this->assertFalse($route->hasParameter('id'));
    }
}
