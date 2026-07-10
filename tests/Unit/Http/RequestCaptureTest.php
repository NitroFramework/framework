<?php

namespace Tests\Unit\Http;

use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Method spoofing and JSON-body handling added to Request::capture().
 */
class RequestCaptureTest extends TestCase
{
    private array $server;
    private array $post;
    private array $get;

    protected function setUp(): void
    {
        [$this->server, $this->post, $this->get] = [$_SERVER, $_POST, $_GET];
    }

    protected function tearDown(): void
    {
        [$_SERVER, $_POST, $_GET] = [$this->server, $this->post, $this->get];
    }

    private function capture(string $method, array $post = [], array $server = []): Request
    {
        $_SERVER = array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/x'], $server);
        $_POST   = $post;
        $_GET    = [];
        $_FILES  = [];
        $_COOKIE = [];

        return Request::capture();
    }

    // ─── Method spoofing (#1) ───────────────────────────────────────────────

    public function test_post_with_method_field_spoofs_to_put(): void
    {
        $this->assertSame('PUT', $this->capture('POST', ['_method' => 'PUT'])->method());
    }

    public function test_method_field_is_case_insensitive(): void
    {
        $this->assertSame('DELETE', $this->capture('POST', ['_method' => 'delete'])->method());
    }

    public function test_disallowed_spoof_method_is_ignored(): void
    {
        // Only PUT/PATCH/DELETE may be spoofed — GET stays POST.
        $this->assertSame('POST', $this->capture('POST', ['_method' => 'GET'])->method());
    }

    public function test_spoofing_only_applies_to_post(): void
    {
        $this->assertSame('GET', $this->capture('GET', ['_method' => 'PUT'])->method());
    }

    public function test_header_override_spoofs(): void
    {
        $this->assertSame(
            'PATCH',
            $this->capture('POST', [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH'])->method()
        );
    }

    public function test_plain_post_stays_post(): void
    {
        $this->assertSame('POST', $this->capture('POST')->method());
    }

    // ─── JSON body accessors (#2) ───────────────────────────────────────────

    public function test_isJson_and_json_accessors(): void
    {
        $req = new Request('POST', '/x', ['content-type' => 'application/json'], [], ['name' => 'Ada', 'age' => 3]);

        $this->assertTrue($req->isJson());
        $this->assertSame(['name' => 'Ada', 'age' => 3], $req->json());
        $this->assertSame('Ada', $req->json('name'));
        $this->assertNull($req->json('missing'));
        // input() reads the same body bag, so JSON payloads work transparently.
        $this->assertSame('Ada', $req->input('name'));
    }

    public function test_non_json_request(): void
    {
        $req = new Request('POST', '/x', ['content-type' => 'application/x-www-form-urlencoded'], [], ['a' => 1]);
        $this->assertFalse($req->isJson());
    }
}
