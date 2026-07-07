<?php

namespace Tests\Unit\Support\Helpers;

use Nitro\Container\Container;
use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The request/url helpers proxy the bound Request rather than reading PHP
 * superglobals directly, so they see exactly what the app's Request sees (and
 * stay correct in worker mode). When no request is bound — console, queued
 * jobs — they fall back to the raw superglobal, preserving old behaviour.
 */
class RequestHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
        $_GET = $_POST = $_FILES = [];
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }

    private function bind(Request $request): void
    {
        Container::getInstance()->instance('request', $request);
    }

    public function test_get_and_post_read_from_the_bound_request(): void
    {
        $this->bind(new Request(
            'POST',
            '/submit',
            [],
            ['q' => 'from-query'],
            ['name' => 'from-body']
        ));

        // Superglobals hold something different — helpers must ignore them.
        $_GET['q'] = 'superglobal';
        $_POST['name'] = 'superglobal';

        $this->assertSame('from-query', get('q'));
        $this->assertSame('from-body', post('name'));
        $this->assertSame('fallback', get('missing', 'fallback'));
    }

    public function test_files_and_has_file_read_from_the_bound_request(): void
    {
        $upload = ['name' => 'a.txt', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/a'];

        $this->bind(new Request('POST', '/upload', [], [], [], ['doc' => $upload]));

        $this->assertSame($upload, files('doc'));
        $this->assertNull(files('missing'));
        $this->assertTrue(has_file('doc'));
        $this->assertFalse(has_file('missing'));
    }

    public function test_url_helpers_use_the_bound_request(): void
    {
        $this->bind(new Request(
            'GET',
            '/dashboard',
            [],
            [],
            [],
            [],
            ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'QUERY_STRING' => 'a=1']
        ));

        $this->assertSame('https://example.test', url());
        $this->assertSame('https://example.test/css/app.css', url('css/app.css'));
        $this->assertSame('https://example.test/dashboard?a=1', current_url());
        $this->assertSame('/dashboard', current_path());
    }

    public function test_helpers_fall_back_to_superglobals_without_a_request(): void
    {
        // No request bound — console/queue context.
        $_GET['page'] = '3';
        $_POST['token'] = 'abc';
        $_SERVER['HTTP_HOST'] = 'cli.test';

        $this->assertNull(nitro_current_request());
        $this->assertSame('3', get('page'));
        $this->assertSame('abc', post('token'));
        $this->assertSame('http://cli.test/x', url('x'));
    }
}
