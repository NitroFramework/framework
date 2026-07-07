<?php

namespace Tests\Unit\Cookie;

use Nitro\Cookie\CookieJar;
use Nitro\Cookie\CookieValuePrefix;
use Nitro\Encryption\Encrypter;
use Nitro\Http\Cookie;
use Nitro\Http\Middleware\EncryptCookies;
use Nitro\Http\Request;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

class CookieTest extends TestCase
{
    private function encrypter(): Encrypter
    {
        return new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
    }

    /** EncryptCookies with a fixed except list (avoids config() in a unit test). */
    private function middleware(Encrypter $enc, array $except = []): EncryptCookies
    {
        return new class($enc, $except) extends EncryptCookies {
            public function __construct(Encrypter $e, private array $skip)
            {
                parent::__construct($e);
            }

            protected function except(): array
            {
                return $this->skip;
            }
        };
    }

    public function test_cookie_renders_a_set_cookie_header(): void
    {
        $header = (new Cookie('sid', 'abc', 0, '/', null, true, true, 'Lax'))->toHeader();

        $this->assertStringContainsString('sid=abc', $header);
        $this->assertStringContainsString('Path=/', $header);
        $this->assertStringContainsString('HttpOnly', $header);
        $this->assertStringContainsString('Secure', $header);
        $this->assertStringContainsString('SameSite=Lax', $header);
    }

    public function test_jar_make_forget_and_queue(): void
    {
        $jar = new CookieJar('/', null, false, 'lax');

        $this->assertSame('theme', $jar->make('theme', 'dark')->name);
        $this->assertTrue($jar->forget('theme')->expiresAt < time());

        $jar->queue('flash', 'hi');
        $this->assertTrue($jar->hasQueued('flash'));
        $this->assertCount(1, $jar->getQueuedCookies());

        $jar->unqueue('flash');
        $this->assertFalse($jar->hasQueued('flash'));
    }

    public function test_value_prefix_binds_value_to_name(): void
    {
        $key = 'k';
        $prefixed = CookieValuePrefix::create('token', $key) . 'payload';

        $this->assertSame('payload', CookieValuePrefix::validate('token', $prefixed, [$key]));
        $this->assertNull(CookieValuePrefix::validate('other', $prefixed, [$key]), 'wrong name is rejected');
    }

    public function test_middleware_encrypts_outgoing_cookies(): void
    {
        $enc = $this->encrypter();
        $mw = $this->middleware($enc);

        $response = $mw->handle(
            new Request('GET', '/'),
            fn ($req) => (new Response(''))->withCookie(new Cookie('token', 'plain-value'))
        );

        $sent = $response->cookies()[0];
        $this->assertNotSame('plain-value', $sent->value);
        $this->assertTrue(Encrypter::appearsEncrypted($sent->value));
    }

    public function test_middleware_decrypts_incoming_cookies(): void
    {
        $enc = $this->encrypter();
        $mw = $this->middleware($enc);

        // Produce an encrypted cookie the way the middleware would.
        $encrypted = $enc->encryptString(CookieValuePrefix::create('token', $enc->getKey()) . 'plain-value');

        $request = new Request('GET', '/', [], [], [], [], [], ['token' => $encrypted]);
        $mw->handle($request, fn ($req) => new Response(''));

        $this->assertSame('plain-value', $request->cookie('token'));
    }

    public function test_tampered_cookie_decrypts_to_null(): void
    {
        $enc = $this->encrypter();
        $mw = $this->middleware($enc);

        $request = new Request('GET', '/', [], [], [], [], [], ['token' => 'not-a-valid-payload']);
        $mw->handle($request, fn ($req) => new Response(''));

        $this->assertNull($request->cookie('token'));
    }

    public function test_excepted_cookie_is_left_untouched(): void
    {
        $enc = $this->encrypter();
        $mw = $this->middleware($enc, ['plain_cookie']);

        $request = new Request('GET', '/', [], [], [], [], [], ['plain_cookie' => 'readable']);
        $response = $mw->handle(
            $request,
            fn ($req) => (new Response(''))->withCookie(new Cookie('plain_cookie', 'readable'))
        );

        $this->assertSame('readable', $request->cookie('plain_cookie'), 'not decrypted');
        $this->assertSame('readable', $response->cookies()[0]->value, 'not encrypted');
    }
}
