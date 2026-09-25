<?php

namespace Nitro\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Nitro\Tests\Fixtures\Classes\Post;
use Nitro\Tests\Fixtures\Classes\Terminable;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every case runs twice: routes registered at boot (uncached) and loaded from the compiled
 * table (cached). Behaviour must be identical, and identical to Laravel's router.
 */
class RoutingTest extends TestCase
{
    #[DataProvider('modes')]
    public function test_controller_actions_and_parameters(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame('home', $this->get('/')->getContent());
        $this->assertSame('id=42', $this->get('/plain/42')->getContent());
        $this->assertSame('invoked', $this->get('/invokable')->getContent());
        $this->assertSame('a=default', $this->get('/optional')->getContent());
        $this->assertSame('a=given', $this->get('/optional/given')->getContent());
        $this->assertSame('id=x', $this->get('/defaults/x')->getContent());
    }

    #[DataProvider('modes')]
    public function test_method_injection_mixes_services_and_positional_parameters(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame(
            ['path' => 'inject/7', 'counter' => true, 'id' => '7'],
            json_decode($this->get('/inject/7')->getContent(), true)
        );
    }

    #[DataProvider('modes')]
    public function test_closure_routes_and_constraints(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame('n=123', $this->get('/numeric/123')->getContent());
        $this->assertSame('fallback', $this->get('/numeric/abc')->getContent());
        $this->assertSame(404, $this->get('/numeric/abc')->getStatusCode());
        $this->assertSame('{"a":1}', $this->get('/array')->getContent());
        $this->assertSame('application/json', $this->get('/array')->headers->get('Content-Type'));
    }

    #[DataProvider('modes')]
    public function test_earlier_dynamic_route_shadows_later_static_route(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame('dynamic:static', $this->get('/order/static')->getContent());
        $this->assertSame('dynamic:other', $this->get('/order/other')->getContent());
    }

    #[DataProvider('modes')]
    public function test_methods_head_options_and_405(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame('POST', $this->call('POST', '/match')->getContent());
        $this->assertSame('GET', $this->get('/match')->getContent());
        $this->assertSame(200, $this->call('HEAD', '/')->getStatusCode());

        $options = $this->call('OPTIONS', '/api/ping');
        $this->assertSame(200, $options->getStatusCode());
        $this->assertStringContainsString('GET', $options->headers->get('Allow'));

        $this->assertSame(405, $this->call('DELETE', '/api/ping')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_domain_routes(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame('account:acme', $this->get('http://acme.example.com/dashboard')->getContent());
        $this->assertSame('fallback', $this->get('http://localhost/dashboard')->getContent());
    }

    #[DataProvider('modes')]
    public function test_implicit_model_and_enum_binding(bool $cached): void
    {
        $app = $this->boot($cached);

        $app->make('db')->connection()->getSchemaBuilder()->create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
        });
        Post::create(['slug' => 'hello-world']);

        $this->assertSame(['id' => 1, 'slug' => 'hello-world'], json_decode($this->get('/posts/1')->getContent(), true));
        $this->assertSame(['id' => 1, 'slug' => 'hello-world'], json_decode($this->get('/slug/hello-world')->getContent(), true));
        $this->assertSame(404, $this->get('/posts/99')->getStatusCode());

        $this->assertSame('published', $this->get('/status/published')->getContent());
        $this->assertSame(404, $this->get('/status/bogus')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_middleware_parameters_global_and_exclusion(bool $cached): void
    {
        $this->boot($cached);

        $response = $this->get('/mw');
        $this->assertSame('hello', $response->headers->get('X-Mw'));
        $this->assertSame('yes', $response->headers->get('X-Global'));


        $this->assertNotNull($this->get('/')->headers->getCookies());
        $this->assertSame([], array_filter(
            $this->get('/no-session')->headers->getCookies(),
            fn ($cookie) => $cookie->getName() === 'nitro_test_session'
        ));
    }

    #[DataProvider('modes')]
    public function test_terminable_middleware(bool $cached): void
    {
        $this->boot($cached);
        Terminable::$terminated = [];

        $this->get('/terminable');

        $this->assertSame(['terminable'], Terminable::$terminated);
    }

    #[DataProvider('modes')]
    public function test_current_route_and_url_generation(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame(
            ['name' => 'current.route', 'router' => 'current.route', 'param' => null],
            json_decode($this->get('/current')->getContent(), true)
        );
        $this->assertSame('abc', $this->get('/current/abc')->getContent());

        $this->assertSame([
            'route' => 'http://localhost/posts/5',
            'plain' => '/plain/7',
            'action' => '/',
        ], json_decode($this->get('/url')->getContent(), true));
    }

    #[DataProvider('modes')]
    public function test_exceptions_render_through_the_handler(bool $cached): void
    {
        $app = $this->boot($cached);

        $this->assertSame(403, $this->get('/abort')->getStatusCode());
        $this->assertSame('from exception', $this->get('/http-response')->getContent());
        $this->assertSame(202, $this->get('/http-response')->getStatusCode());

        $json = $this->get('/abort', ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame('nope', json_decode($json->getContent(), true)['message']);

        $app->make('config')->set('app.debug', false);
        $this->assertSame("custom teapot page\n", $this->get('/teapot')->getContent());
    }

    #[DataProvider('modes')]
    public function test_form_requests_validate_on_resolution(bool $cached): void
    {
        $this->boot($cached);

        $ok = $this->call('POST', '/form', ['title' => 'Hello'], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(['validated' => ['title' => 'Hello']], json_decode($ok->getContent(), true));

        $bad = $this->call('POST', '/form', ['title' => 'x'], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertArrayHasKey('title', json_decode($bad->getContent(), true)['errors']);
    }

    #[DataProvider('modes')]
    public function test_auth_middleware(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame(401, $this->get('/me', ['HTTP_ACCEPT' => 'application/json'])->getStatusCode());
    }

    public function test_routes_added_after_the_cache_is_loaded_are_still_matched(): void
    {
        $app = $this->boot(cached: true);

        $app->make('router')->get('/late/{x}', fn ($x) => "late:{$x}")->name('late');
        $app->make('router')->getRoutes()->refreshNameLookups();

        $this->assertSame('late:1', $this->get('/late/1')->getContent());
        $this->assertTrue($app->make('router')->has('late'));
        $this->assertTrue($app->make('router')->has('home'));
    }
}
