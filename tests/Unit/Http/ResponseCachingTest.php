<?php

namespace Tests\Unit\Http;

use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/** HTTP caching helpers on Response (#4). */
class ResponseCachingTest extends TestCase
{
    public function test_cache_sets_private_max_age_by_default(): void
    {
        $this->assertSame('private, max-age=60', (new Response())->cache(60)->header('Cache-Control'));
    }

    public function test_cache_public(): void
    {
        $this->assertSame('public, max-age=120', (new Response())->cache(120, true)->header('Cache-Control'));
    }

    public function test_no_cache(): void
    {
        $response = (new Response())->noCache();
        $this->assertStringContainsString('no-store', $response->header('Cache-Control'));
        $this->assertSame('no-cache', $response->header('Pragma'));
    }

    public function test_last_modified_from_timestamp(): void
    {
        $this->assertSame(
            'Thu, 01 Jan 1970 00:00:00 GMT',
            (new Response())->lastModified(0)->header('Last-Modified')
        );
    }

    public function test_last_modified_from_datetime(): void
    {
        $dt = new \DateTimeImmutable('@1000000000');
        $this->assertSame(
            gmdate('D, d M Y H:i:s', 1000000000) . ' GMT',
            (new Response())->lastModified($dt)->header('Last-Modified')
        );
    }

    public function test_etag_strong_and_weak(): void
    {
        $this->assertSame('"abc"', (new Response())->etag('abc')->header('ETag'));
        $this->assertSame('W/"abc"', (new Response())->etag('abc', true)->header('ETag'));
        // Existing quotes aren't doubled.
        $this->assertSame('"abc"', (new Response())->etag('"abc"')->header('ETag'));
    }

    public function test_not_modified_empties_body_and_sets_304(): void
    {
        $response = (new Response('big body'))->notModified();
        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function test_helpers_are_chainable(): void
    {
        $response = (new Response())->cache(30)->etag('v1')->lastModified(0);
        $this->assertSame('private, max-age=30', $response->header('Cache-Control'));
        $this->assertSame('"v1"', $response->header('ETag'));
    }
}
