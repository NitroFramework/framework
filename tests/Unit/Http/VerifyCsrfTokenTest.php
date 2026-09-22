<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
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
        // The token is read from the session the route started, not from
        // $_SESSION: nothing outside StartSession may bring a session into
        // being, so a test that wants one starts it the same way a request
        // would.
        Container::setInstance(new Container());

        $session = new Store('test_sess', new ArraySessionHandler());
        $session->start();
        $session->put('_csrf', self::TOKEN);

        Container::getInstance()->instance('session', $session);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
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

    /**
     * The token is published as a cookie a script can read.
     *
     * {@see VerifyCsrfToken::tokenFrom()} accepts an X-XSRF-TOKEN header, but
     * a client can only send that if something gave it the value first. A
     * form reads it from `@csrf`; a client that builds its own requests —
     * Inertia, or anything on fetch — reads this cookie. Without it the
     * header was an input nothing could supply.
     */
    public function test_a_readable_xsrf_cookie_is_published(): void
    {
        $response = (new VerifyCsrfToken())->handle($this->request('GET', '/dashboard'), $this->next());

        $cookie = $this->cookieNamed($response, 'XSRF-TOKEN');

        $this->assertNotNull($cookie, 'the token must be published for non-form clients');
        $this->assertSame(self::TOKEN, $cookie->value);
    }

    /** Http-only would make it unreadable, and so unusable. */
    public function test_the_xsrf_cookie_is_reachable_from_script(): void
    {
        $response = (new VerifyCsrfToken())->handle($this->request('GET', '/dashboard'), $this->next());

        $this->assertFalse(
            $this->cookieNamed($response, 'XSRF-TOKEN')?->httpOnly ?? true,
            'a cookie script cannot read is a token it cannot echo back'
        );
    }

    /** Cookies are queued as a list, so it is found by name rather than keyed. */
    private function cookieNamed(Response $response, string $name): ?\Nitro\Http\Cookie
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** A rejected request gets no cookie, because it never reaches the response. */
    public function test_a_rejected_request_publishes_nothing(): void
    {
        $mw = new VerifyCsrfToken();

        try {
            $mw->handle($this->request('POST', '/save'), $this->next());
            $this->fail('a POST without a token must be rejected');
        } catch (HttpException) {
            $this->addToAssertionCount(1);
        }
    }
}
