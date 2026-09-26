<?php

namespace Nitro\Tests\Compat;

use Illuminate\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator as LaravelUrlGenerator;
use Nitro\Routing\CompiledRoutes;
use Nitro\Routing\UrlGenerator;
use Nitro\Tests\Fixtures\Classes\Post;
use Nitro\Tests\Fixtures\Classes\Status;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * route() through Nitro's compiled URL templates gives exactly what stock Laravel's generator
 * gives: the same URL, or the same exception with the same message, for every route shape,
 * parameter shape, value and URL setting below (uncached and from the route cache).
 */
class UrlGenerationTest extends TestCase
{
    private const ROUTES = [
        'home', 'plain', 'posts.show', 'url.two', 'url.optional', 'url.slug', 'url.locale',
        'url.domain', 'url.prefixed.edit', 'current.route',
    ];

    /**
     * Request URLs the generator works from: plain http, https on a non-standard port, a
     * subdirectory install (base URL) and a host with a trailing dot-free subdomain.
     */
    private const REQUESTS = [
        'http://example.test/',
        'https://example.test:8443/',
        'https://example.test/sub/index.php/page',
        'http://www.example.test/deep/path?x=1',
    ];

    #[DataProvider('modes')]
    public function test_route_matches_laravel_for_every_case(bool $cached): void
    {
        $nitro = $this->generate($this->url($this->boot($cached)));
        $this->assertInstanceOf(UrlGenerator::class, $this->app->make('url'));

        $this->flushGlobalState();

        $laravel = $this->generate($this->url($this->bootLaravel()));

        $this->assertSame(count($laravel), count($nitro));

        foreach ($laravel as $case => $expected) {
            $this->assertSame($expected, $nitro[$case], $case);
        }
    }

    #[DataProvider('modes')]
    public function test_url_settings_are_honoured_as_laravel_honours_them(bool $cached): void
    {
        $settings = [
            'forced root' => fn (UrlGeneratorContract $url) => $url->forceRootUrl('https://cdn.example.test/app'),
            'forced scheme' => fn (UrlGeneratorContract $url) => $url->forceScheme('https'),
            'defaults' => fn (UrlGeneratorContract $url) => $url->defaults(['locale' => 'en', 'id' => 3]),
            'host formatter' => fn (UrlGeneratorContract $url) => $url->formatHostUsing(fn ($root) => $root.'/h'),
            'path formatter' => fn (UrlGeneratorContract $url) => $url->formatPathUsing(fn ($path) => $path.'/p'),
            'root with placeholder' => fn (UrlGeneratorContract $url) => $url->forceRootUrl('https://{tenant}.example.test'),
        ];

        foreach ($settings as $name => $apply) {
            $nitroUrl = $this->url($this->boot($cached));
            $apply($nitroUrl);
            $nitro = $this->generate($nitroUrl, requests: ['https://example.test/']);
            $this->flushGlobalState();

            $laravelUrl = $this->url($this->bootLaravel());
            $apply($laravelUrl);
            $laravel = $this->generate($laravelUrl, requests: ['https://example.test/']);
            $this->flushGlobalState();

            foreach ($laravel as $case => $expected) {
                $this->assertSame($expected, $nitro[$case], "{$name}: {$case}");
            }
        }
    }

    public function test_only_plain_named_routes_get_a_template(): void
    {
        $routes = $this->boot(cached: true)->make('router')->getRoutes();

        $this->assertInstanceOf(CompiledRoutes::class, $routes);
        $this->assertSame(['plain/{id}', ['id']], $routes->urlTemplate('plain'));
        $this->assertSame(['u/{first}/and/{second}', ['first', 'second']], $routes->urlTemplate('url.two'));
        $this->assertSame(['u/prefixed/{id}/edit', ['id']], $routes->urlTemplate('url.prefixed.edit'));

        foreach (['url.optional', 'url.slug', 'url.domain', 'missing'] as $name) {
            $this->assertNull($routes->urlTemplate($name), $name);
        }
    }

    public function test_laravels_generator_is_used_when_turned_off(): void
    {
        $app = $this->boot();
        $app->forgetInstance('url');
        $app['config']->set('nitro.compile.urls', false);

        $this->assertSame(LaravelUrlGenerator::class, get_class($this->url($app)));
    }

    /**
     * A request as the web server hands it over; one under /sub/index.php runs from a
     * subdirectory through its front controller, so it has a base URL.
     */
    private static function request(string $uri): Request
    {
        $server = str_contains($uri, '/sub/index.php')
            ? ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/var/www/sub/index.php', 'PHP_SELF' => '/sub/index.php/page']
            : [];

        return Request::create($uri, 'GET', [], [], [], $server);
    }

    /**
     * The application's URL generator, with a request bound as a handled request has one.
     */
    private function url(LaravelApplication $app): UrlGeneratorContract
    {
        if (! $app->bound('request')) {
            $app->instance('request', Request::create('http://example.test/'));
        }

        return $app->make('url');
    }

    /**
     * Every route × parameter shape × absolute / relative × request, as URL or exception.
     *
     * @param  list<string>|null  $requests
     * @return array<string, string>
     */
    private function generate(UrlGeneratorContract $url, ?array $requests = null): array
    {
        $post = (new Post)->forceFill(['id' => 5, 'slug' => 'hello-world']);
        $keyless = (new Post)->forceFill(['id' => null]);

        $values = [
            'int' => 7, 'zero' => 0, 'negative' => -1, 'string' => 'abc', 'zero string' => '0',
            'space' => 'a b', 'slash' => 'a/b', 'slashes around' => '/a/', 'percent' => 'x%y',
            'question' => 'x?y', 'hash' => 'x#y', 'unicode' => 'ünï', 'brace' => '{x}', 'close brace' => 'x}',
            'empty' => '', 'null' => null, 'float' => 1.5, 'true' => true, 'enum' => Status::Published,
            'model' => $post, 'keyless model' => $keyless, 'reserved' => ':@;,=+!*|&$',
        ];

        $shapes = ['none' => [], 'null' => null];

        foreach ($values as $name => $value) {
            $shapes["bare {$name}"] = $value;
            $shapes["list {$name}"] = [$value];
            $shapes["named id {$name}"] = ['id' => $value];
            $shapes["named post {$name}"] = ['post' => $value];
        }

        $shapes += [
            'two list' => ['a', 'b'],
            'two named' => ['first' => 'a', 'second' => 'b'],
            'two named reversed' => ['second' => 'b', 'first' => 'a'],
            'two mixed' => ['first' => 'a', 'b'],
            'one of two' => ['a'],
            'three list' => ['a', 'b', 'c'],
            'extra query' => ['id' => 7, 'page' => 2],
            'extra positional' => [7, 8],
            'non-list keys' => [1 => 'x'],
            'locale' => ['locale' => 'fr'],
            'team' => ['team' => 'acme'],
            'team and query' => ['acme', 'q' => 'x'],
            'nested array' => ['id' => ['a', 'b']],
        ];

        $results = [];

        foreach ($requests ?? self::REQUESTS as $request) {
            $url->setRequest(self::request($request));

            foreach (self::ROUTES as $route) {
                foreach ($shapes as $shape => $parameters) {
                    foreach ([true, false] as $absolute) {
                        try {
                            $result = $url->route($route, $parameters, $absolute);
                        } catch (Throwable $e) {
                            $result = get_class($e).': '.$e->getMessage();
                        }

                        $results["{$request} {$route} {$shape} ".($absolute ? 'absolute' : 'relative')] = $result;
                    }
                }
            }
        }

        return $results;
    }
}
