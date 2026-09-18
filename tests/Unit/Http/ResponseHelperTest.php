<?php

namespace Tests\Unit\Http;

use Nitro\Foundation\Application;
use Nitro\Http\Response;
use Nitro\Http\ResponseFactory;
use PHPUnit\Framework\TestCase;

/**
 * The response() helper.
 *
 * It used to echo its content and return void, which meant a controller could
 * not return it and response()->view() was impossible. It now mirrors the
 * framework it takes its vocabulary from: no arguments gives the factory.
 */
class ResponseHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(dirname(__DIR__, 3));
        $application->bootstrap();

        restore_error_handler();
        restore_exception_handler();
    }

    public function test_no_arguments_gives_the_factory(): void
    {
        $this->assertInstanceOf(ResponseFactory::class, response());
    }

    public function test_content_gives_a_response(): void
    {
        $response = response('hello', 201);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('hello', $response->getContent());
        $this->assertSame(201, $response->getStatusCode());
    }

    /** Nothing may be written to the output buffer just by building a response. */
    public function test_building_a_response_emits_nothing(): void
    {
        ob_start();
        response('hello');
        $emitted = ob_get_clean();

        $this->assertSame('', $emitted);
    }

    public function test_headers_are_carried(): void
    {
        $response = response('hello', 200, ['X-Test' => 'yes']);

        $this->assertSame('yes', $response->header('X-Test'));
    }

    /** The factory is what makes the fluent forms reachable. */
    public function test_the_factory_builds_json(): void
    {
        $response = response()->json(['ok' => true]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('"ok":true', $response->getContent());
    }
}
