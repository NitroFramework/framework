<?php

namespace Tests\Unit\Exceptions;

use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * Giving a refusal its HTTP identity.
 *
 * Middleware can refuse a request on its own, but only middleware — and "who is
 * this, and may they?" gets asked a long way from there: in an action, in a
 * component, inside a service two calls down. Without exceptions for it, those
 * places can only abort(), which throws away the difference between "sign in"
 * and "signing in will not help", and between "forbidden" and "as far as you
 * are concerned this does not exist".
 */
class ExceptionParityTest extends TestCase
{
    private function handler(): ExceptionHandler
    {
        $config = new class implements ConfigRepository {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        return new ExceptionHandler($config, new Container());
    }

    // ─── Status ───────────────────────────────────────────

    public function test_not_signed_in_is_a_401(): void
    {
        $prepared = $this->handler()->prepareException(new AuthenticationException());

        $this->assertInstanceOf(HttpException::class, $prepared);
        $this->assertSame(401, $prepared->getStatusCode());
    }

    public function test_not_allowed_is_a_403(): void
    {
        $prepared = $this->handler()->prepareException(new AuthorizationException());

        $this->assertSame(403, $prepared->getStatusCode());
    }

    public function test_a_refusal_can_answer_as_a_404_instead(): void
    {
        // Telling somebody they are forbidden from reading an order confirms
        // the order exists. Most of an application answers 404 for that reason.
        $prepared = $this->handler()->prepareException(
            (new AuthorizationException())->asNotFound(),
        );

        $this->assertSame(404, $prepared->getStatusCode());
    }

    public function test_the_guards_that_were_tried_are_carried(): void
    {
        $exception = new AuthenticationException('Unauthenticated.', ['web', 'api'], '/login');

        $this->assertSame(['web', 'api'], $exception->guards());
        $this->assertSame('/login', $exception->redirectTo());
    }

    // ─── Reporting ────────────────────────────────────────

    public function test_a_refusal_is_not_logged_as_a_failure(): void
    {
        $handler = $this->handler();

        // Somebody not being signed in is the application working. Logging
        // every one of them buries the exceptions worth reading under the
        // ordinary traffic of a login page.
        $this->assertTrue($handler->shouldntReport(new AuthenticationException()));
        $this->assertTrue($handler->shouldntReport(new AuthorizationException()));
    }

    public function test_an_application_can_ask_to_see_them_after_all(): void
    {
        $handler = $this->handler()->stopIgnoring(AuthorizationException::class);

        // A pattern of refused requests is worth watching for.
        $this->assertTrue($handler->shouldReport(new AuthorizationException()));
    }

    public function test_a_real_failure_is_still_reported(): void
    {
        $this->assertTrue($this->handler()->shouldReport(new \RuntimeException('boom')));
    }

    // ─── Headers ──────────────────────────────────────────

    public function test_an_http_exception_carries_headers(): void
    {
        $exception = (new HttpException(429, 'Too Many Requests'))->retryAfter(60);

        // A 429 without Retry-After tells a client to back off for an unknown
        // length of time, so it retries immediately.
        $this->assertSame(['Retry-After' => '60'], $exception->getHeaders());
    }

    public function test_headers_accumulate(): void
    {
        $exception = (new HttpException(401))
            ->withHeaders(['WWW-Authenticate' => 'Bearer'])
            ->withHeaders(['X-Reason' => 'expired']);

        $this->assertSame(
            ['WWW-Authenticate' => 'Bearer', 'X-Reason' => 'expired'],
            $exception->getHeaders(),
        );
    }

    public function test_an_exception_with_no_headers_has_none(): void
    {
        $this->assertSame([], (new HttpException(404))->getHeaders());
    }
}
