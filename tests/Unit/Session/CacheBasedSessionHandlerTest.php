<?php

namespace Tests\Unit\Session;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use Nitro\Session\CacheBasedSessionHandler;
use Nitro\Session\NullSessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * Sessions kept in a cache store, and the handler that discards them.
 */
class CacheBasedSessionHandlerTest extends TestCase
{
    private function handler(int $minutes = 120): CacheBasedSessionHandler
    {
        return new CacheBasedSessionHandler(new Repository(new ArrayStore()), $minutes);
    }

    // ─── Cache-backed ─────────────────────────────────────────────────────

    public function test_a_payload_written_can_be_read_back(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'the-payload');

        $this->assertSame('the-payload', $handler->read('abc'));
    }

    public function test_an_unknown_id_reads_as_an_empty_session(): void
    {
        $this->assertSame('', $this->handler()->read('nope'));
    }

    public function test_destroying_removes_the_entry(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'the-payload');
        $handler->destroy('abc');

        $this->assertSame('', $handler->read('abc'));
    }

    /** Expiry is the store's job, so the handler must not try to sweep. */
    public function test_garbage_collection_is_left_to_the_store(): void
    {
        $this->assertSame(0, $this->handler()->gc(3600));
    }

    public function test_the_backing_repository_is_reachable(): void
    {
        $this->assertInstanceOf(Repository::class, $this->handler()->getCache());
    }

    public function test_a_session_round_trips_through_the_cache(): void
    {
        $handler = $this->handler();

        $session = new Store('nitro_session', $handler);
        $session->start();
        $session->put('user', 'ada');
        $session->save();

        $resumed = new Store('nitro_session', $handler, $session->getId());
        $resumed->start();

        $this->assertSame('ada', $resumed->get('user'));
    }

    // ─── Null ─────────────────────────────────────────────────────────────

    public function test_the_null_handler_keeps_nothing(): void
    {
        $handler = new NullSessionHandler();

        $this->assertTrue($handler->write('abc', 'the-payload'));
        $this->assertSame('', $handler->read('abc'));
    }

    /** A session on the null handler starts empty however much was put in it. */
    public function test_a_session_on_the_null_handler_never_persists(): void
    {
        $handler = new NullSessionHandler();

        $session = new Store('nitro_session', $handler);
        $session->start();
        $session->put('user', 'ada');
        $session->save();

        $resumed = new Store('nitro_session', $handler, $session->getId());
        $resumed->start();

        $this->assertNull($resumed->get('user'));
    }
}
