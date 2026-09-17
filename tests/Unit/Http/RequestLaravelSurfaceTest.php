<?php

namespace Tests\Unit\Http;

use Nitro\Http\Request;
use Nitro\Routing\Route;
use PHPUnit\Framework\TestCase;

/**
 * The Request surface an application writes against: route/user binding,
 * path matching, content negotiation, tokens and input helpers.
 */
class RequestLaravelSurfaceTest extends TestCase
{
    private function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        array $query = [],
        array $body = [],
        array $server = [],
        array $cookies = []
    ): Request {
        return new Request($method, $path, $headers, $query, $body, [], $server, $cookies);
    }

    // ─── Route binding ────────────────────────────────────

    public function test_route_is_null_before_routing(): void
    {
        $this->assertNull($this->request()->route());
        $this->assertFalse($this->request()->routeIs('anything'));
    }

    public function test_route_returns_the_matched_route_and_its_parameters(): void
    {
        $route = new Route('closure', fn () => null, ['post' => '42'], [], [], 'posts.show');

        $request = $this->request('GET', '/posts/42');
        $request->setRouteResolver(static fn () => $route);

        $this->assertSame($route, $request->route());
        $this->assertSame('42', $request->route('post'));
        $this->assertSame('fallback', $request->route('missing', 'fallback'));
    }

    public function test_route_is_matches_names_and_wildcards(): void
    {
        $route = new Route('closure', fn () => null, [], [], [], 'admin.posts.edit');

        $request = $this->request();
        $request->setRouteResolver(static fn () => $route);

        $this->assertTrue($request->routeIs('admin.posts.edit'));
        $this->assertTrue($request->routeIs('admin.*'));
        $this->assertTrue($request->routeIs('nope', 'admin.posts.*'));
        $this->assertFalse($request->routeIs('admin.users.*'));
        $this->assertFalse($request->routeIs('posts.edit'));
    }

    // ─── Path matching ────────────────────────────────────

    public function test_is_matches_the_path_with_wildcards(): void
    {
        $request = $this->request('GET', '/admin/posts/42');

        $this->assertTrue($request->is('admin/posts/42'));
        $this->assertTrue($request->is('admin/*'));
        $this->assertTrue($request->is('nope', 'admin/posts/*'));
        $this->assertFalse($request->is('admin/users/*'));
    }

    public function test_segments(): void
    {
        $request = $this->request('GET', '/posts/42/edit');

        $this->assertSame(['posts', '42', 'edit'], $request->segments());
        $this->assertSame('posts', $request->segment(1));
        $this->assertSame('42', $request->segment(2));
        $this->assertNull($request->segment(9));
        $this->assertSame('dflt', $request->segment(9, 'dflt'));
    }

    public function test_decoded_path(): void
    {
        $this->assertSame('/a b/c', $this->request('GET', '/a%20b/c')->decodedPath());
    }

    // ─── URL building ─────────────────────────────────────

    public function test_host_and_root(): void
    {
        $request = $this->request('GET', '/x', [], [], [], [
            'HTTP_HOST' => 'example.test:8080',
        ]);

        $this->assertSame('example.test:8080', $request->httpHost());
        $this->assertSame('example.test', $request->host());
        $this->assertSame('http', $request->scheme());
        $this->assertSame('http://example.test:8080', $request->schemeAndHttpHost());
        $this->assertSame('http://example.test:8080', $request->root());
    }

    public function test_full_url_with_and_without_query(): void
    {
        $request = $this->request('GET', '/search', [], ['q' => 'php', 'page' => '2'], [], [
            'HTTP_HOST' => 'example.test',
        ]);

        $this->assertStringContainsString('page=3', $request->fullUrlWithQuery(['page' => 3]));
        $this->assertStringContainsString('q=php', $request->fullUrlWithQuery(['page' => 3]));

        $without = $request->fullUrlWithoutQuery(['page']);
        $this->assertStringContainsString('q=php', $without);
        $this->assertStringNotContainsString('page=', $without);
    }

    // ─── Content negotiation ──────────────────────────────

    public function test_acceptable_content_types_sort_by_quality(): void
    {
        $request = $this->request('GET', '/', [
            'accept' => 'text/html;q=0.8, application/json;q=0.9, */*;q=0.1',
        ]);

        $this->assertSame(
            ['application/json', 'text/html', '*/*'],
            $request->getAcceptableContentTypes()
        );
    }

    public function test_accepts_and_wants_json(): void
    {
        $json = $this->request('GET', '/', ['accept' => 'application/json']);

        $this->assertTrue($json->acceptsJson());
        $this->assertTrue($json->wantsJson());
        $this->assertFalse($json->acceptsHtml());

        $html = $this->request('GET', '/', ['accept' => 'text/html,application/xhtml+xml']);

        $this->assertTrue($html->acceptsHtml());
        $this->assertFalse($html->wantsJson());
    }

    public function test_wildcard_accept_takes_anything(): void
    {
        $request = $this->request('GET', '/', ['accept' => '*/*']);

        $this->assertTrue($request->acceptsAnyContentType());
        $this->assertTrue($request->accepts('application/pdf'));
        $this->assertFalse($request->wantsJson());
    }

    public function test_subtype_wildcard_matches(): void
    {
        $request = $this->request('GET', '/', ['accept' => 'image/*']);

        $this->assertTrue($request->accepts('image/png'));
        $this->assertFalse($request->accepts('application/json'));
    }

    public function test_prefers_picks_the_first_acceptable(): void
    {
        $request = $this->request('GET', '/', [
            'accept' => 'application/json;q=0.9, text/html;q=0.5',
        ]);

        $this->assertSame('application/json', $request->prefers(['text/html', 'application/json']));
        $this->assertNull($request->prefers(['application/pdf']));
    }

    public function test_format(): void
    {
        $this->assertSame('json', $this->request('GET', '/', ['accept' => 'application/json'])->format());
        $this->assertSame('html', $this->request('GET', '/', ['accept' => 'text/html'])->format());
        $this->assertSame('html', $this->request('GET', '/')->format());
        $this->assertSame('txt', $this->request('GET', '/', ['accept' => 'text/plain'])->format());
    }

    public function test_missing_accept_header_accepts_everything(): void
    {
        $request = $this->request('GET', '/');

        $this->assertSame([], $request->getAcceptableContentTypes());
        $this->assertTrue($request->accepts('application/json'));
        $this->assertTrue($request->acceptsAnyContentType());
    }

    // ─── Headers and tokens ───────────────────────────────

    public function test_bearer_token(): void
    {
        $this->assertSame(
            'abc123',
            $this->request('GET', '/', ['authorization' => 'Bearer abc123'])->bearerToken()
        );

        // Case-insensitive scheme.
        $this->assertSame(
            'abc123',
            $this->request('GET', '/', ['authorization' => 'bearer abc123'])->bearerToken()
        );

        $this->assertNull($this->request('GET', '/', ['authorization' => 'Basic xyz'])->bearerToken());
        $this->assertNull($this->request('GET', '/', ['authorization' => 'Bearer   '])->bearerToken());
        $this->assertNull($this->request()->bearerToken());
    }

    public function test_has_header_and_user_agent(): void
    {
        $request = $this->request('GET', '/', ['user-agent' => 'nitro-test']);

        $this->assertTrue($request->hasHeader('user-agent'));
        $this->assertFalse($request->hasHeader('x-nope'));
        $this->assertSame('nitro-test', $request->userAgent());
    }

    public function test_has_cookie(): void
    {
        $request = $this->request('GET', '/', [], [], [], [], ['theme' => 'dark']);

        $this->assertTrue($request->hasCookie('theme'));
        $this->assertFalse($request->hasCookie('missing'));
    }

    public function test_prefetch_detection(): void
    {
        $this->assertTrue($this->request('GET', '/', ['purpose' => 'prefetch'])->prefetch());
        $this->assertTrue($this->request('GET', '/', ['sec-purpose' => 'prefetch'])->prefetch());
        $this->assertFalse($this->request('GET', '/')->prefetch());
    }

    public function test_pjax(): void
    {
        $this->assertTrue($this->request('GET', '/', ['x-pjax' => 'true'])->pjax());
        $this->assertFalse($this->request('GET', '/')->pjax());
    }

    /** ips() must not trust forwarding headers from an untrusted peer. */
    public function test_ips_ignores_forwarded_header_without_a_trusted_proxy(): void
    {
        $request = $this->request('GET', '/', ['x-forwarded-for' => '1.2.3.4'], [], [], [
            'REMOTE_ADDR' => '10.0.0.9',
        ]);

        $this->assertSame(['10.0.0.9'], $request->ips());
    }

    // ─── Input helpers ────────────────────────────────────

    public function test_keys(): void
    {
        $request = $this->request('POST', '/', [], ['q' => '1'], ['name' => 'a']);

        $this->assertEqualsCanonicalizing(['q', 'name'], $request->keys());
    }

    public function test_merge_if_missing_keeps_existing_values(): void
    {
        $request = $this->request('POST', '/', [], [], ['name' => 'original']);

        $request->mergeIfMissing(['name' => 'replacement', 'role' => 'admin']);

        $this->assertSame('original', $request->input('name'));
        $this->assertSame('admin', $request->input('role'));
    }

    public function test_replace_swaps_the_body(): void
    {
        $request = $this->request('POST', '/', [], [], ['a' => 1, 'b' => 2]);

        $request->replace(['c' => 3]);

        $this->assertNull($request->post('a'));
        $this->assertSame(3, $request->post('c'));
    }

    public function test_to_array(): void
    {
        $request = $this->request('POST', '/', [], ['q' => '1'], ['name' => 'a']);

        $this->assertSame(['q' => '1', 'name' => 'a'], $request->toArray());
    }
}
