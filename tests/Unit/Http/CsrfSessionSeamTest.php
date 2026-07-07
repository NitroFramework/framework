<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * CSRF must flow through the session Store (the single seam), independent of the
 * driver. Regression: csrf_token() used raw $_SESSION, so with a non-native
 * (worker-safe file/array) store — as FrankenPHP worker mode uses — it minted a
 * fresh token every request and every POST/Livewire/HTMX call 419'd. This test
 * binds an ARRAY store (no $_SESSION at all) and proves the token is stable,
 * lives in the Store, and verifies through the middleware. Its absence is what
 * let the worker-mode CSRF bug ship.
 */
class CsrfSessionSeamTest extends TestCase
{
    private Store $session;

    protected function setUp(): void
    {
        Container::reset();
        $this->session = new Store('test_sess', new ArraySessionHandler());
        $this->session->start();
        Container::getInstance()->instance('session', $this->session);
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function test_token_is_stable_and_stored_in_the_session_store(): void
    {
        $a = csrf_token();
        $b = csrf_token();

        $this->assertNotEmpty($a);
        $this->assertSame($a, $b, 'token must be stable (persisted in the session, not regenerated each call)');
        $this->assertSame($a, $this->session->get('_csrf'), 'token must live in the session Store, not $_SESSION');
    }

    public function test_middleware_passes_a_matching_token(): void
    {
        $token = csrf_token();
        $request = new Request('POST', '/x', [], [], ['_token' => $token]);

        $reached = false;
        (new VerifyCsrfToken())->handle($request, function () use (&$reached) {
            $reached = true;
            return Response::html('ok');
        });

        $this->assertTrue($reached, 'a POST carrying the stored token must pass CSRF verification');
    }

    public function test_middleware_rejects_a_missing_token(): void
    {
        csrf_token(); // mint one in the store
        $request = new Request('POST', '/x', [], [], []); // no _token

        $this->expectException(HttpException::class);
        (new VerifyCsrfToken())->handle($request, fn() => Response::html('ok'));
    }
}
