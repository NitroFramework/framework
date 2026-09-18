<?php

namespace Tests\Unit\Session;

use Nitro\Cookie\CookieJar;
use Nitro\Http\Request;
use Nitro\Session\CookieSessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The cookie driver, which keeps the payload on the client.
 *
 * Nothing is written server-side, so the round trip only closes when the
 * queued cookie is handed back on the next request.
 */
class CookieSessionHandlerTest extends TestCase
{
    private CookieJar $cookies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookies = new CookieJar();
    }

    private function handler(int $minutes = 120): CookieSessionHandler
    {
        return new CookieSessionHandler($this->cookies, $minutes);
    }

    private function request(array $cookies = []): Request
    {
        return new Request('GET', '/', cookies: $cookies);
    }

    /** The value the jar queued under a name, or null. */
    private function queued(string $name): ?string
    {
        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie->value;
            }
        }

        return null;
    }

    public function test_writing_queues_the_payload_as_a_cookie(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'the-payload');

        $queued = $this->queued('abc');

        $this->assertNotNull($queued);
        $this->assertSame('the-payload', json_decode($queued, true)['data']);
    }

    public function test_reading_returns_the_payload_from_the_request_cookie(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'the-payload');

        $next = $this->handler();
        $next->setRequest($this->request(['abc' => $this->queued('abc')]));

        $this->assertSame('the-payload', $next->read('abc'));
    }

    public function test_reading_without_a_request_returns_nothing(): void
    {
        $this->assertSame('', $this->handler()->read('abc'));
    }

    public function test_an_unparseable_cookie_reads_as_an_empty_session(): void
    {
        $handler = $this->handler();
        $handler->setRequest($this->request(['abc' => 'not-json']));

        $this->assertSame('', $handler->read('abc'));
    }

    /** An expired payload must not be honoured even though the cookie arrived. */
    public function test_an_expired_payload_reads_as_an_empty_session(): void
    {
        $stale = json_encode(['data' => 'the-payload', 'expires' => time() - 60]);

        $handler = $this->handler();
        $handler->setRequest($this->request(['abc' => $stale]));

        $this->assertSame('', $handler->read('abc'));
    }

    public function test_destroying_queues_a_forget_cookie(): void
    {
        $handler = $this->handler();
        $handler->destroy('abc');

        $this->assertSame('', $this->queued('abc'));
    }

    public function test_garbage_collection_is_the_clients_business(): void
    {
        $this->assertSame(0, $this->handler()->gc(3600));
    }

    /** The whole point: a session survives a request without server-side storage. */
    public function test_a_session_round_trips_through_the_cookie(): void
    {
        $handler = $this->handler();
        $session = new Store('nitro_session', $handler);
        $session->setRequestOnHandler($this->request());
        $session->start();
        $session->put('user', 'ada');
        $session->save();

        $carried = $this->queued($session->getId());

        $next = $this->handler();
        $resumed = new Store('nitro_session', $next, $session->getId());
        $resumed->setRequestOnHandler($this->request([$session->getId() => $carried]));
        $resumed->start();

        $this->assertSame('ada', $resumed->get('user'));
    }

    public function test_the_store_knows_this_handler_wants_the_request(): void
    {
        $session = new Store('nitro_session', $this->handler());

        $this->assertTrue($session->handlerNeedsRequest());
    }
}
