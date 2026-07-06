<?php

namespace Tests\Unit\Http;

use Nitro\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * abort() throws an HttpException (routed through the Kernel's exception
 * handler) rather than echoing an <h1> and exit()-ing, which bypassed the whole
 * lifecycle. HttpException extends RuntimeException, matching callers' docs.
 */
class AbortHelperTest extends TestCase
{
    public function test_abort_throws_http_exception_with_status(): void
    {
        try {
            abort(404, 'Missing');
            $this->fail('abort() did not throw.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Missing', $e->getMessage());
        }
    }

    public function test_abort_defaults_message_to_status_text(): void
    {
        try {
            abort(419);
            $this->fail('abort() did not throw.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
            $this->assertSame('Page Expired', $e->getMessage());
        }
    }

    public function test_abort_exception_is_a_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        abort(403);
    }
}
