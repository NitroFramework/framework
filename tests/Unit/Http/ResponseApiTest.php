<?php

namespace Tests\Unit\Http;

use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseApiTest extends TestCase
{
    public function test_status_and_content_accessors(): void
    {
        $r = new Response('hello', 201, ['X-Test' => 'yes']);
        $this->assertSame('hello', $r->getContent());
        $this->assertSame(201, $r->getStatusCode());
    }

    public function test_header_dual_mode_acts_as_getter_or_setter(): void
    {
        $r = new Response();

        // Setter: returns $this for chaining.
        $this->assertSame($r, $r->header('X-One', '1'));
        // Getter: returns the value.
        $this->assertSame('1', $r->header('X-One'));
        $this->assertNull($r->header('X-Missing'));
    }

    public function test_with_headers_applies_multiple_and_returns_self(): void
    {
        $r = new Response();
        $returned = $r->withHeaders(['A' => '1', 'B' => '2']);

        $this->assertSame($r, $returned);
        $this->assertSame('1', $r->header('A'));
        $this->assertSame('2', $r->header('B'));
    }

    public function test_headers_returns_all(): void
    {
        $r = new Response('', 200, ['A' => '1']);
        $this->assertSame(['A' => '1'], $r->headers());
    }

    public function test_factories_set_correct_status_and_content_type(): void
    {
        $json = Response::json(['ok' => true]);
        $this->assertSame(200, $json->getStatusCode());
        $this->assertStringContainsString('application/json', $json->header('Content-Type'));

        $html = Response::html('<h1>hi</h1>');
        $this->assertSame(200, $html->getStatusCode());
        $this->assertStringContainsString('text/html', $html->header('Content-Type'));

        $redir = Response::redirect('/login');
        $this->assertSame(302, $redir->getStatusCode());
        $this->assertSame('/login', $redir->header('Location'));

        $notFound = Response::notFound();
        $this->assertSame(404, $notFound->getStatusCode());
    }
}
