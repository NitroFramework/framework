<?php

namespace Tests\Unit\Http;

use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Laravel-style Request API plus the deprecated legacy aliases
 * we kept for backward compatibility. Both must behave identically until the
 * aliases are removed in a future major.
 */
class RequestApiTest extends TestCase
{
    protected function request(): Request
    {
        return new Request(
            method:  'POST',
            path:    '/users/42',
            headers: ['content-type' => 'application/json', 'user-agent' => 'unit-test'],
            query:   ['ref' => 'home'],
            body:    ['name' => 'Alice', 'age' => 30],
            files:   ['avatar' => ['name' => 'a.png', 'error' => UPLOAD_ERR_OK]],
            server:  ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTPS' => 'on', 'HTTP_HOST' => 'example.com'],
        );
    }

    protected function tearDown(): void
    {
        // Drop any config we bound so trusted-proxy state never leaks between tests.
        \Nitro\Container\Container::getInstance()->forget('config');
        parent::tearDown();
    }

    /** Bind a minimal config so config('app.trusted_proxies') resolves in-test. */
    private function trustProxies(array $proxies): void
    {
        \Nitro\Container\Container::getInstance()->instance(
            'config',
            \Nitro\Foundation\Config::fromArray(['app' => ['trusted_proxies' => $proxies]]),
        );
    }

    // ─── New (Laravel-style) accessors ────────────────────────────────────

    public function test_method_returns_upper_case_verb(): void
    {
        $this->assertSame('POST', $this->request()->method());
    }

    public function test_path_returns_normalized_path(): void
    {
        $this->assertSame('/users/42', $this->request()->path());
    }

    public function test_header_returns_case_insensitive_value(): void
    {
        $this->assertSame('application/json', $this->request()->header('Content-Type'));
        $this->assertSame('application/json', $this->request()->header('content-type'));
        $this->assertNull($this->request()->header('nope'));
        $this->assertSame('fallback', $this->request()->header('nope', 'fallback'));
    }

    public function test_headers_returns_all(): void
    {
        $this->assertArrayHasKey('user-agent', $this->request()->headers());
    }

    public function test_query_returns_all_or_one(): void
    {
        $req = $this->request();
        $this->assertSame(['ref' => 'home'], $req->query());
        $this->assertSame('home', $req->query('ref'));
        $this->assertSame('fallback', $req->query('missing', 'fallback'));
    }

    public function test_post_returns_all_or_one(): void
    {
        $req = $this->request();
        $this->assertSame(['name' => 'Alice', 'age' => 30], $req->post());
        $this->assertSame('Alice', $req->post('name'));
        $this->assertNull($req->post('missing'));
    }

    public function test_input_merges_post_over_query(): void
    {
        $req = new Request('POST', '/x', body: ['k' => 'body'], query: ['k' => 'query', 'extra' => '1']);
        $this->assertSame('body', $req->input('k'));
        $this->assertSame('1', $req->input('extra'));
        $this->assertSame('default', $req->input('missing', 'default'));
    }

    public function test_all_files_returns_uploaded_files(): void
    {
        $this->assertArrayHasKey('avatar', $this->request()->allFiles());
    }

    public function test_server_returns_all_or_one(): void
    {
        $req = $this->request();
        $this->assertSame('on', $req->server('HTTPS'));
        $this->assertSame('default', $req->server('nope', 'default'));
        $this->assertIsArray($req->server());
    }

    public function test_ip_ignores_forwarded_for_from_untrusted_client(): void
    {
        // Secure default: with no trusted proxies configured, X-Forwarded-For is
        // ignored (a client can't spoof its IP) — we use REMOTE_ADDR.
        $req = new Request('GET', '/', server: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 198.51.100.10',
            'REMOTE_ADDR'          => '10.0.0.1',
        ]);
        $this->assertSame('10.0.0.1', $req->ip());
    }

    public function test_ip_picks_first_forwarded_for_from_trusted_proxy(): void
    {
        $this->trustProxies(['10.0.0.1']);
        $req = new Request('GET', '/', server: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 198.51.100.10',
            'REMOTE_ADDR'          => '10.0.0.1',
        ]);
        $this->assertSame('203.0.113.5', $req->ip());
    }

    public function test_ip_falls_back_to_remote_addr(): void
    {
        $req = new Request('GET', '/', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame('127.0.0.1', $req->ip());
    }

    public function test_is_method_is_case_insensitive(): void
    {
        $this->assertTrue($this->request()->isMethod('post'));
        $this->assertTrue($this->request()->isMethod('POST'));
        $this->assertFalse($this->request()->isMethod('GET'));
    }

    public function test_ajax_detects_xhr(): void
    {
        $this->assertTrue($this->request()->ajax());
    }

    public function test_secure_reads_https(): void
    {
        $this->assertTrue($this->request()->secure());
    }

    public function test_url_composes_scheme_host_path(): void
    {
        $this->assertSame('https://example.com/users/42', $this->request()->url());
    }

    public function test_has_returns_true_when_any_provided_key_missing(): void
    {
        $req = $this->request();
        $this->assertTrue($req->has('name'));
        $this->assertTrue($req->has('ref'));
        $this->assertFalse($req->has('name', 'missing'));
        $this->assertFalse($req->has());
    }
}
