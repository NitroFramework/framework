<?php

namespace Tests\Unit\Http;

use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Request now captures cookies (Laravel's $request->cookies) so the framework
 * reads them through the Request instead of $_COOKIE.
 */
class RequestCookieTest extends TestCase
{
    private function request(array $cookies): Request
    {
        return new Request('GET', '/', [], [], [], [], [], $cookies);
    }

    public function test_reads_a_named_cookie(): void
    {
        $r = $this->request(['nitro_session' => 'abc123']);
        $this->assertSame('abc123', $r->cookie('nitro_session'));
    }

    public function test_missing_cookie_returns_default(): void
    {
        $r = $this->request([]);
        $this->assertNull($r->cookie('nope'));
        $this->assertSame('fallback', $r->cookie('nope', 'fallback'));
    }

    public function test_all_cookies(): void
    {
        $r = $this->request(['a' => '1', 'b' => '2']);
        $this->assertSame(['a' => '1', 'b' => '2'], $r->cookie());
    }
}
