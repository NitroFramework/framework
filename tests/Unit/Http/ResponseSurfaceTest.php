<?php

namespace Tests\Unit\Http;

use Nitro\Http\Cookie;
use Nitro\Http\Exceptions\HttpResponseException;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Status, content, header, cookie and exception surface on the Response.
 */
class ResponseSurfaceTest extends TestCase
{
    // ─── Status and content ───────────────────────────────

    public function test_status_reads_and_writes(): void
    {
        $response = new Response('body', 201);

        $this->assertSame(201, $response->status());
        $this->assertSame($response, $response->status(404));
        $this->assertSame(404, $response->status());
    }

    public function test_status_text(): void
    {
        $this->assertSame('OK', (new Response('', 200))->statusText());
        $this->assertSame('Not Found', (new Response('', 404))->statusText());
        $this->assertSame('Unprocessable Content', (new Response('', 422))->statusText());
        $this->assertSame('Unknown Status', (new Response('', 799))->statusText());
    }

    public function test_content_reads_and_writes(): void
    {
        $response = new Response('first');

        $this->assertSame('first', $response->content());
        $this->assertSame($response, $response->content('second'));
        $this->assertSame('second', $response->content());
    }

    /** A JSON response keeps the array it was built from. */
    public function test_original_content_survives_encoding(): void
    {
        $response = Response::json(['a' => 1, 'b' => [2, 3]]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $response->getOriginalContent());
        $this->assertSame('{"a":1,"b":[2,3]}', $response->getContent());
    }

    public function test_original_content_falls_back_to_the_body(): void
    {
        $this->assertSame('plain', (new Response('plain'))->getOriginalContent());
    }

    // ─── Status inspection ────────────────────────────────

    public function test_status_predicates(): void
    {
        $this->assertTrue((new Response('', 200))->isOk());
        $this->assertTrue((new Response('', 204))->isSuccessful());
        $this->assertTrue((new Response('', 204))->isEmpty());

        $this->assertTrue((new Response('', 302))->isRedirect());
        $this->assertFalse((new Response('', 200))->isRedirect());

        $this->assertTrue((new Response('', 404))->isNotFound());
        $this->assertTrue((new Response('', 404))->isClientError());
        $this->assertTrue((new Response('', 403))->isForbidden());

        $this->assertTrue((new Response('', 500))->isServerError());
        $this->assertFalse((new Response('', 500))->isClientError());
    }

    public function test_is_redirect_can_check_the_location(): void
    {
        $response = new Response('', 302, ['Location' => '/home']);

        $this->assertTrue($response->isRedirect('/home'));
        $this->assertFalse($response->isRedirect('/elsewhere'));
    }

    /** Header names are case-insensitive over the wire. */
    public function test_is_redirect_matches_location_case_insensitively(): void
    {
        $response = new Response('', 302, ['location' => '/home']);

        $this->assertTrue($response->isRedirect('/home'));
    }

    // ─── Headers ──────────────────────────────────────────

    public function test_without_header_is_case_insensitive(): void
    {
        $response = new Response('', 200, ['X-Custom' => 'a', 'Content-Type' => 'text/html']);

        $response->withoutHeader('x-custom');

        $this->assertFalse($response->hasHeader('X-Custom'));
        $this->assertTrue($response->hasHeader('content-type'));
    }

    public function test_with_headers_merges(): void
    {
        $response = (new Response())->withHeaders(['A' => '1', 'B' => '2']);

        $this->assertSame('1', $response->header('A'));
        $this->assertSame('2', $response->header('B'));
    }

    // ─── Cookies ──────────────────────────────────────────

    public function test_cookie_accepts_a_built_cookie(): void
    {
        $response = (new Response())->cookie(new Cookie('theme', 'dark'));

        $this->assertCount(1, $response->cookies());
        $this->assertSame('theme', $response->cookies()[0]->name);
    }

    public function test_cookie_builds_from_arguments(): void
    {
        $response = (new Response())->cookie('theme', 'dark', 60);

        $this->assertCount(1, $response->cookies());
        $this->assertSame('dark', $response->cookies()[0]->value);
        $this->assertGreaterThan(time(), $response->cookies()[0]->expiresAt);
    }

    public function test_zero_minutes_makes_a_session_cookie(): void
    {
        $response = (new Response())->cookie('theme', 'dark');

        $this->assertSame(0, $response->cookies()[0]->expiresAt);
    }

    public function test_with_cookies_attaches_several(): void
    {
        $response = (new Response())->withCookies([
            new Cookie('a', '1'),
            new Cookie('b', '2'),
        ]);

        $this->assertCount(2, $response->cookies());
    }

    /**
     * Expiring a cookie must still send one: a browser only forgets a cookie it
     * is told to, so dropping it from the list would leave it in place.
     */
    public function test_without_cookie_queues_an_expired_one(): void
    {
        $response = (new Response())
            ->cookie('theme', 'dark')
            ->withoutCookie('theme');

        $this->assertCount(1, $response->cookies());
        $this->assertLessThan(time(), $response->cookies()[0]->expiresAt);
        $this->assertSame('', $response->cookies()[0]->value);
    }

    public function test_without_cookies_expires_several(): void
    {
        $response = (new Response())->withoutCookies(['a', 'b']);

        $this->assertCount(2, $response->cookies());

        foreach ($response->cookies() as $cookie) {
            $this->assertLessThan(time(), $cookie->expiresAt);
        }
    }

    // ─── Exceptions ───────────────────────────────────────

    public function test_with_exception_records_it(): void
    {
        $exception = new \RuntimeException('boom');
        $response = (new Response('', 500))->withException($exception);

        $this->assertSame($exception, $response->getException());
    }

    public function test_throw_response_carries_the_response(): void
    {
        $response = new Response('payload', 418);

        try {
            $response->throwResponse();
            $this->fail('throwResponse() should have thrown');
        } catch (HttpResponseException $exception) {
            $this->assertSame($response, $exception->getResponse());
            $this->assertSame(418, $exception->getResponse()->status());
        }
    }
}
