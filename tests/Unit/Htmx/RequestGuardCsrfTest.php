<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Htmx\Support\RequestGuard;
use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The HTMX request guard verifies the CSRF token on state-changing requests
 * using a constant-time comparison (hash_equals) so response timing can't leak
 * how much of the token an attacker guessed.
 */
class RequestGuardCsrfTest extends TestCase
{
    private const TOKEN = 'htmx-session-token';

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_csrf'] = self::TOKEN;
    }

    private function guard(): RequestGuard
    {
        return new RequestGuard(Container::getInstance());
    }

    private function request(string $method, array $body = [], array $headers = []): Request
    {
        return new Request($method, '/x', $headers, [], $body);
    }

    public function test_post_with_matching_field_token_passes(): void
    {
        $this->guard()->guard($this->request('POST', ['_csrf' => self::TOKEN]));
        $this->addToAssertionCount(1);
    }

    public function test_post_with_matching_header_token_passes(): void
    {
        $this->guard()->guard($this->request('POST', [], ['x-csrf-token' => self::TOKEN]));
        $this->addToAssertionCount(1);
    }

    public function test_get_skips_csrf(): void
    {
        $this->guard()->guard($this->request('GET'));
        $this->addToAssertionCount(1);
    }

    public function test_post_with_wrong_token_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0); // HttpException carries status separately
        try {
            $this->guard()->guard($this->request('POST', ['_csrf' => 'wrong']));
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_post_without_token_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->guard()->guard($this->request('POST'));
    }

    public function test_disabled_csrf_lets_anything_through(): void
    {
        $guard = new RequestGuard(Container::getInstance(), csrfEnabled: false);
        $guard->guard($this->request('POST', ['_csrf' => 'wrong']));
        $this->addToAssertionCount(1);
    }
}
