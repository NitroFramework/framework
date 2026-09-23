<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Exceptions\RouteNotFoundException;
use Nitro\Routing\Exceptions\UrlGenerationException;
use Nitro\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way Laravel lets a route be written, declared here and exercised.
 *
 * The point is not that each method exists — a surface diff answers that — but
 * that a route declared the Laravel way answers the URL a Laravel developer
 * expects, with the parameters they expect, and generates back the URL they
 * started from. A method that is present and behaves differently is worse than
 * one that is absent, because nothing says so.
 */
class LaravelRouteStylesTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new class implements ConfigRepository {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'app.controllers_namespace' => 'App\\Controllers',
                    'app.debug' => true,
                    default => $default,
                };
            }

            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        $this->router = new Router($config);
    }

    /** Which route answers a request, and with which parameters. */
    private function match(string $method, string $path): ?array
    {
        $route = $this->router->findMatchingRoute(new Request($method, $path));

        return $route === null
            ? null
            : ['name' => $route->getName(), 'parameters' => $route->getParameters()];
    }

    // ─── Verbs ────────────────────────────────────────────

    #[DataProvider('verbs')]
    public function test_each_verb_registers_and_matches(string $verb): void
    {
        $this->router->{strtolower($verb)}('/things', fn () => 'x')->name('things');

        $this->assertSame('things', $this->match($verb, '/things')['name'] ?? null);
    }

    public static function verbs(): array
    {
        return [['GET'], ['POST'], ['PUT'], ['PATCH'], ['DELETE'], ['OPTIONS']];
    }

    public function test_match_registers_a_chosen_set_of_verbs(): void
    {
        $this->router->match(['GET', 'POST'], '/contact', fn () => 'x')->name('contact');

        $this->assertSame('contact', $this->match('GET', '/contact')['name']);
        $this->assertSame('contact', $this->match('POST', '/contact')['name']);
        $this->assertNull($this->match('DELETE', '/contact'));
    }

    public function test_any_registers_every_common_verb(): void
    {
        $this->router->any('/webhook', fn () => 'x')->name('webhook');

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $verb) {
            $this->assertSame('webhook', $this->match($verb, '/webhook')['name'], $verb);
        }
    }

    /** HTTP requires HEAD wherever GET is served. */
    public function test_head_falls_back_to_get(): void
    {
        $this->router->get('/health', fn () => 'x')->name('health');

        $this->assertSame('health', $this->match('HEAD', '/health')['name']);
    }

    // ─── Parameters ───────────────────────────────────────

    public function test_a_required_parameter_is_captured_by_name(): void
    {
        $this->router->get('/users/{id}', fn () => 'x')->name('users.show');

        $this->assertSame(['id' => '7'], $this->match('GET', '/users/7')['parameters']);
    }

    public function test_several_parameters_keep_their_order_and_names(): void
    {
        $this->router->get('/users/{user}/posts/{post}', fn () => 'x')->name('users.posts');

        $this->assertSame(
            ['user' => '7', 'post' => '3'],
            $this->match('GET', '/users/7/posts/3')['parameters'],
        );
    }

    public function test_an_optional_parameter_matches_with_and_without(): void
    {
        $this->router->get('/files/{path?}', fn () => 'x')->name('files');

        $this->assertSame('files', $this->match('GET', '/files')['name']);
        $this->assertSame(['path' => 'a.pdf'], $this->match('GET', '/files/a.pdf')['parameters']);
    }

    /** Laravel answers with the named parameters only. */
    public function test_parameters_are_named_only(): void
    {
        $this->router->get('/users/{id}', fn () => 'x')->name('users.show');

        $parameters = $this->match('GET', '/users/7')['parameters'];

        $this->assertSame(['id'], array_keys($parameters));
    }

    // ─── Constraints ──────────────────────────────────────

    public function test_where_constrains_a_parameter(): void
    {
        $this->router->get('/orders/{order}', fn () => 'x')->name('orders')->where('order', '[0-9]+');

        $this->assertSame('orders', $this->match('GET', '/orders/42')['name']);
        $this->assertNull($this->match('GET', '/orders/abc'));
    }

    #[DataProvider('constraintHelpers')]
    public function test_a_constraint_helper_accepts_and_rejects(
        string $helper,
        string $accepted,
        string $rejected,
    ): void {
        $this->router->get('/v/{value}', fn () => 'x')->name('v')->{$helper}('value');

        $this->assertSame('v', $this->match('GET', "/v/{$accepted}")['name'], "{$helper} should accept {$accepted}");
        $this->assertNull($this->match('GET', "/v/{$rejected}"), "{$helper} should reject {$rejected}");
    }

    public static function constraintHelpers(): array
    {
        return [
            'whereNumber'       => ['whereNumber', '42', 'abc'],
            'whereAlpha'        => ['whereAlpha', 'abc', '42'],
            'whereAlphaNumeric' => ['whereAlphaNumeric', 'a1', 'a-1'],
            'whereUuid'         => ['whereUuid', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'nope'],
            'whereUlid'         => ['whereUlid', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'nope'],
        ];
    }

    public function test_where_in_limits_a_parameter_to_a_set(): void
    {
        $this->router->get('/reports/{period}', fn () => 'x')->name('reports')
            ->whereIn('period', ['daily', 'weekly']);

        $this->assertSame('reports', $this->match('GET', '/reports/daily')['name']);
        $this->assertNull($this->match('GET', '/reports/hourly'));
    }

    /** A global pattern applies without every route repeating it. */
    public function test_a_global_pattern_applies_to_every_route(): void
    {
        $this->router->pattern('id', '[0-9]+');
        $this->router->get('/items/{id}', fn () => 'x')->name('items');

        $this->assertSame('items', $this->match('GET', '/items/9')['name']);
        $this->assertNull($this->match('GET', '/items/nine'));
    }

    // ─── Groups ───────────────────────────────────────────

    public function test_a_group_prefixes_paths_and_names(): void
    {
        $this->router->group(['prefix' => 'admin', 'as' => 'admin.'], function (Router $router): void {
            $router->get('/users', fn () => 'x')->name('users');
        });

        $this->assertSame('admin.users', $this->match('GET', '/admin/users')['name']);
    }

    public function test_groups_nest(): void
    {
        $this->router->group(['prefix' => 'api', 'as' => 'api.'], function (Router $router): void {
            $router->group(['prefix' => 'v1', 'as' => 'v1.'], function (Router $router): void {
                $router->get('/status', fn () => 'x')->name('status');
            });
        });

        $this->assertSame('api.v1.status', $this->match('GET', '/api/v1/status')['name']);
    }

    public function test_the_fluent_group_builders_compose(): void
    {
        $this->router->prefix('shop')->group(function (Router $router): void {
            $router->get('/cart', fn () => 'x')->name('cart');
        });

        $this->assertSame('cart', $this->match('GET', '/shop/cart')['name']);
    }

    // ─── Resources ────────────────────────────────────────

    public function test_a_resource_registers_the_seven_routes(): void
    {
        $this->router->resource('photos', 'PhotoController');

        $expected = [
            ['GET', '/photos', 'photos.index'],
            ['GET', '/photos/create', 'photos.create'],
            ['POST', '/photos', 'photos.store'],
            ['GET', '/photos/1', 'photos.show'],
            ['GET', '/photos/1/edit', 'photos.edit'],
            ['PUT', '/photos/1', 'photos.update'],
            ['DELETE', '/photos/1', 'photos.destroy'],
        ];

        foreach ($expected as [$verb, $path, $name]) {
            $this->assertSame($name, $this->match($verb, $path)['name'] ?? null, "{$verb} {$path}");
        }
    }

    /**
     * An API resource has no create or edit form.
     *
     * /widgets/create is not absent, it is show with the id 'create' — the
     * same answer Laravel gives, because {widget} matches any segment. The
     * edit route is the one that genuinely goes away.
     */
    public function test_an_api_resource_leaves_out_the_form_routes(): void
    {
        $this->router->apiResource('widgets', 'WidgetController');

        $this->assertSame('widgets.index', $this->match('GET', '/widgets')['name']);
        $this->assertSame('widgets.show', $this->match('GET', '/widgets/create')['name']);
        $this->assertNull($this->match('GET', '/widgets/1/edit'));
    }

    public function test_a_resource_can_be_limited_with_only(): void
    {
        $this->router->resource('books', 'BookController')->only(['index', 'show']);

        $this->assertSame('books.index', $this->match('GET', '/books')['name']);
        $this->assertSame('books.show', $this->match('GET', '/books/1')['name']);
        $this->assertNull($this->match('POST', '/books'));
    }

    public function test_a_resource_can_exclude_with_except(): void
    {
        $this->router->resource('tags', 'TagController')->except(['destroy']);

        $this->assertSame('tags.index', $this->match('GET', '/tags')['name']);
        $this->assertNull($this->match('DELETE', '/tags/1'));
    }

    /** The options array is still accepted, as it was before the fluent form. */
    public function test_a_resource_still_takes_its_options_as_an_array(): void
    {
        $this->router->resource('notes', 'NoteController', ['only' => ['index']]);

        $this->assertSame('notes.index', $this->match('GET', '/notes')['name']);
        $this->assertNull($this->match('POST', '/notes'));
    }

    public function test_a_resource_can_rename_its_parameter(): void
    {
        $this->router->resource('users', 'UserController')->parameter('admin');

        $this->assertSame(['admin' => '3'], $this->match('GET', '/users/3')['parameters']);
    }

    public function test_a_resource_can_be_named_differently(): void
    {
        $this->router->resource('photos', 'PhotoController')->names('gallery');

        $this->assertSame('gallery.index', $this->match('GET', '/photos')['name']);
    }

    /** Several at once, each pending registration discarded as the loop turns. */
    public function test_resources_registers_every_one_given(): void
    {
        $this->router->resources([
            'posts'    => 'PostController',
            'comments' => 'CommentController',
        ]);

        $this->assertSame('posts.index', $this->match('GET', '/posts')['name']);
        $this->assertSame('comments.show', $this->match('GET', '/comments/1')['name']);
    }

    public function test_api_resources_registers_every_one_given(): void
    {
        $this->router->apiResources(['boxes' => 'BoxController']);

        $this->assertSame('boxes.index', $this->match('GET', '/boxes')['name']);
        $this->assertNull($this->match('GET', '/boxes/1/edit'));
    }

    // ─── Other registration styles ────────────────────────

    public function test_a_redirect_route_registers(): void
    {
        $this->router->redirect('/here', '/there');

        $this->assertNotNull($this->match('GET', '/here'));
    }

    public function test_a_view_route_registers(): void
    {
        $this->router->view('/about', 'pages.about');

        $this->assertNotNull($this->match('GET', '/about'));
    }

    public function test_a_fallback_is_reported(): void
    {
        $this->router->get('/known', fn () => 'x')->name('known');
        $this->router->fallback(fn () => 'missing');

        $this->assertTrue($this->router->hasFallback());
        $this->assertNull($this->match('GET', '/unknown'));
    }

    // ─── Named-route URL generation ───────────────────────

    public function test_a_url_is_generated_from_a_name(): void
    {
        $this->router->get('/users/{id}', fn () => 'x')->name('users.show');

        $this->assertSame('/users/7', $this->router->route('users.show', ['id' => 7]));
    }

    public function test_an_extra_parameter_becomes_a_query_string(): void
    {
        $this->router->get('/users/{id}', fn () => 'x')->name('users.show');

        $this->assertSame(
            '/users/7?tab=posts',
            $this->router->route('users.show', ['id' => 7, 'tab' => 'posts']),
        );
    }

    public function test_an_unfilled_optional_falls_away(): void
    {
        $this->router->get('/files/{path?}', fn () => 'x')->name('files');

        $this->assertSame('/files', $this->router->route('files'));
    }

    /** The round trip is the whole point: a built URL must match its own route. */
    public function test_a_generated_url_matches_the_route_that_built_it(): void
    {
        $this->router->get('/docs/{path}', fn () => 'x')->name('docs')->where('path', '.*');

        $url = $this->router->route('docs', ['path' => 'guides/install/windows.md']);

        $this->assertSame('/docs/guides/install/windows.md', $url);
        $this->assertSame('docs', $this->match('GET', $url)['name']);
    }

    // ─── Failures ─────────────────────────────────────────

    public function test_an_unknown_name_throws_route_not_found(): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('Route [nope.at.all] not defined.');

        $this->router->route('nope.at.all');
    }

    public function test_a_missing_parameter_throws_url_generation_and_names_it(): void
    {
        $this->router->get('/users/{id}/posts/{post}', fn () => 'x')->name('users.posts');

        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionMessage('post');

        $this->router->route('users.posts', ['id' => 7]);
    }

    /** Both stayed InvalidArgumentException subclasses, so old catches still hold. */
    public function test_both_failures_remain_invalid_argument_exceptions(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, RouteNotFoundException::forName('x'));
        $this->assertInstanceOf(\InvalidArgumentException::class, UrlGenerationException::forMissingParameters('x'));
    }
}
