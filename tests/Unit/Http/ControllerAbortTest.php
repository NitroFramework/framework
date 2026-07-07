<?php

namespace Tests\Unit\Http;

use Nitro\Exceptions\HttpException;
use Nitro\Http\Controller\Concerns\BuildsResponses;
use PHPUnit\Framework\TestCase;

/**
 * The BuildsResponses::abort() controller helper throws an HttpException so the
 * failure routes through the Kernel's exception handler — content negotiation,
 * status code and response-ready hooks. It must NOT send output or exit()
 * directly: an exit() would kill a FrankenPHP worker and bypass the lifecycle.
 */
class ControllerAbortTest extends TestCase
{
    private function controller(): object
    {
        return new class {
            use BuildsResponses;

            // Expose the protected trait method for testing.
            public function callAbort(int $code, string $message = ''): never
            {
                $this->abort($code, $message);
            }
        };
    }

    public function test_abort_throws_http_exception_with_status(): void
    {
        try {
            $this->controller()->callAbort(403, 'Nope');
            $this->fail('abort() did not throw.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Nope', $e->getMessage());
        }
    }

    public function test_abort_defaults_message_to_status_text(): void
    {
        try {
            $this->controller()->callAbort(404);
            $this->fail('abort() did not throw.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Not Found', $e->getMessage());
        }
    }
}
