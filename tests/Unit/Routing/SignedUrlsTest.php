<?php

namespace Tests\Unit\Routing;

use Closure;
use Nitro\Encryption\MissingAppKeyException;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Middleware\ValidateSignature;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Contracts\SignsUrls;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Signed URLs: a link that can be handed to somebody with no session and
 * still be trusted.
 *
 * The signature covers the path and the query, keyed by the application key,
 * so changing any part of the URL invalidates it. It does not cover the host,
 * which keeps the link working behind a proxy or under a second hostname.
 */
class SignedUrlsTest extends TestCase
{
    private function router(string $key = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => match ($name) {
                'app.controllers_namespace' => 'App\\Controllers\\',
                'app.key' => $key,
                default => $default,
            }
        );

        $router = new Router($config, new RouteTypes());
        $router->get('/unsubscribe/{user}', fn () => 'bye')->name('unsub');

        return $router;
    }

    /** Turn a generated URL back into the request a browser would send. */
    private function requestFor(string $url, ?string $path = null): Request
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return new Request('GET', $path ?? (string) parse_url($url, PHP_URL_PATH), [], $query);
    }

    public function test_the_router_offers_the_capability(): void
    {
        $this->assertInstanceOf(SignsUrls::class, $this->router());
    }

    public function test_a_signed_url_verifies(): void
    {
        $router = $this->router();

        $url = $router->signedRoute('unsub', ['user' => 7]);

        $this->assertStringStartsWith('/unsubscribe/7?', $url);
        $this->assertTrue($router->hasValidSignature($this->requestFor($url)));
    }

    public function test_an_unsigned_url_does_not_verify(): void
    {
        $router = $this->router();

        $this->assertFalse($router->hasValidSignature(new Request('GET', '/unsubscribe/7')));
    }

    /** The whole point: the URL cannot be edited after it is issued. */
    public function test_a_tampered_path_does_not_verify(): void
    {
        $router = $this->router();

        $url = $router->signedRoute('unsub', ['user' => 7]);

        $this->assertFalse($router->hasValidSignature($this->requestFor($url, '/unsubscribe/8')));
    }

    public function test_a_tampered_query_does_not_verify(): void
    {
        $router = $this->router();

        $url = $router->signedRoute('unsub', ['user' => 7, 'role' => 'guest']);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query['role'] = 'admin';

        $this->assertFalse($router->hasValidSignature(new Request('GET', '/unsubscribe/7', [], $query)));
    }

    public function test_a_forged_signature_does_not_verify(): void
    {
        $router = $this->router();

        $request = new Request('GET', '/unsubscribe/7', [], ['signature' => str_repeat('0', 64)]);

        $this->assertFalse($router->hasValidSignature($request));
    }

    /** A different key must not validate another app's links. */
    public function test_a_url_signed_with_another_key_does_not_verify(): void
    {
        $issuer = $this->router();
        $other = $this->router('base64:' . base64_encode(str_repeat('z', 32)));

        $url = $issuer->signedRoute('unsub', ['user' => 7]);

        $this->assertTrue($issuer->hasValidSignature($this->requestFor($url)));
        $this->assertFalse($other->hasValidSignature($this->requestFor($url)));
    }

    public function test_a_temporary_url_verifies_before_it_expires(): void
    {
        $router = $this->router();

        $url = $router->temporarySignedRoute('unsub', 600, ['user' => 7]);

        $this->assertStringContainsString('expires=', $url);
        $this->assertTrue($router->hasValidSignature($this->requestFor($url)));
    }

    public function test_an_expired_url_does_not_verify(): void
    {
        $router = $this->router();

        $url = $router->temporarySignedRoute('unsub', -60, ['user' => 7]);

        $this->assertFalse($router->hasValidSignature($this->requestFor($url)));
    }

    /** Pushing the expiry out has to break the signature, or it means nothing. */
    public function test_an_extended_expiry_does_not_verify(): void
    {
        $router = $this->router();

        $url = $router->temporarySignedRoute('unsub', -60, ['user' => 7]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query['expires'] = time() + 3600;

        $this->assertFalse($router->hasValidSignature(new Request('GET', '/unsubscribe/7', [], $query)));
    }

    /**
     * A browser, a mail client and a proxy may all reorder query parameters,
     * so the signature is computed over a sorted query.
     */
    public function test_the_query_order_does_not_matter(): void
    {
        $router = $this->router();

        $url = $router->signedRoute('unsub', ['user' => 7, 'ref' => 'email', 'campaign' => 'winter']);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertTrue($router->hasValidSignature(
            new Request('GET', '/unsubscribe/7', [], array_reverse($query, true))
        ));
    }

    /** Signatures are worthless without a secret, so say so rather than issue one. */
    public function test_signing_without_an_application_key_is_refused(): void
    {
        $router = $this->router('');

        $this->expectException(MissingAppKeyException::class);

        $router->signedRoute('unsub', ['user' => 7]);
    }

    // ─── The middleware ───────────────────────────────────────────────────

    public function test_the_middleware_passes_a_valid_signature(): void
    {
        $router = $this->router();
        $url = $router->signedRoute('unsub', ['user' => 7]);

        $response = (new ValidateSignature($router))->handle(
            $this->requestFor($url),
            static fn (): Response => Response::html('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_the_middleware_refuses_an_invalid_signature(): void
    {
        $router = $this->router();

        $this->expectException(HttpException::class);

        (new ValidateSignature($router))->handle(
            new Request('GET', '/unsubscribe/7'),
            static fn (): Response => Response::html('ok'),
        );
    }

    /**
     * A router that cannot verify must fail closed. A signature check that
     * quietly passes is worse than not having one at all.
     */
    public function test_the_middleware_refuses_a_router_that_cannot_verify(): void
    {
        $router = $this->createMock(RouterInterface::class);

        $this->assertNotInstanceOf(SignsUrls::class, $router);

        $this->expectException(HttpException::class);

        (new ValidateSignature($router))->handle(
            new Request('GET', '/unsubscribe/7', [], ['signature' => 'anything']),
            static fn (): Response => Response::html('ok'),
        );
    }
}
