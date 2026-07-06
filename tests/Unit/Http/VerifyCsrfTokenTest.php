<?php

namespace Tests\Unit\Http;

use Nitro\Exceptions\HttpException;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * VerifyCsrfToken: read verbs and exempt paths pass; state-changing requests
 * must carry a token (form field or header) that matches the session token,
 * compared in constant time. Missing/mismatched tokens throw a 419 so the
 * Kernel can render it (never echo+exit like the bare abort() helper).
 */
class VerifyCsrfTokenTest extends TestCase
{
    private const TOKEN = 'valid-session-token';

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_csrf'] = self::TOKEN;
    }

    private function request(string $method, string $path, array $body = [], array $headers = []): Request
    {
        return new Request($method, $path, $headers, [], $body);
    }

    /** Passing $next: returns a sentinel Response so we can assert it ran. */
    private function next(): callable
    {
        return fn(Request $req) => Response::html('passed');
    }

    public function test_get_request_passes_without_token(): void
    {
        $mw = new VerifyCsrfToken();
        $response = $mw->handle($this->request('GET', '/'), $this->next());

        $this->assertSame('passed', $response->getContent());
    }

    public function test_post_with_matching_form_token_passes(): void
    {
        $mw = new VerifyCsrfToken();
        $response = $mw->handle(
            $this->request('POST', '/save', ['_token' => self::TOKEN]),
            $this->next(),
        );

        $this->assertSame('passed', $response->getContent());
    }

    public function test_post_with_matching_header_token_passes(): void
    {
        $mw = new VerifyCsrfToken();
        $response = $mw->handle(
            // Request stores headers keyed lower-case.
            $this->request('POST', '/save', [], ['x-csrf-token' => self::TOKEN]),
            $this->next(),
        );

        $this->assertSame('passed', $response->getContent());
    }

    public function test_post_with_wrong_token_throws_419(): void
    {
        $mw = new VerifyCsrfToken();

        try {
            $mw->handle($this->request('POST', '/save', ['_token' => 'wrong']), $this->next());
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }

    public function test_post_without_token_throws_419(): void
    {
        $mw = new VerifyCsrfToken();

        $this->expectException(HttpException::class);
        $mw->handle($this->request('POST', '/save'), $this->next());
    }

    public function test_exempt_path_passes_without_token(): void
    {
        $mw = new VerifyCsrfToken(['webhooks/*']);
        $response = $mw->handle($this->request('POST', '/webhooks/stripe'), $this->next());

        $this->assertSame('passed', $response->getContent());
    }
}
