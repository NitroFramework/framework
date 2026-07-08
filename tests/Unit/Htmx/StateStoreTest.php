<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Htmx\State\ArrayStateStore;
use Nitro\Htmx\State\SessionStateStore;
use Nitro\Htmx\State\StateStore;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The component-state backends behind HasAutoState. All implement the same
 * tiny get/put/forget contract; the interesting behaviour is that
 * SessionStateStore routes through the framework session Store (the seam)
 * when one is bound and only falls back to raw $_SESSION when none is —
 * never reading the superglobal out from under an active session.
 */
class StateStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::reset();
    }

    // ── ArrayStateStore (in-memory, used by the ComponentHarness) ─────────

    public function test_array_store_round_trips_and_forgets(): void
    {
        $store = new ArrayStateStore();
        $this->assertNull($store->get('missing'));

        $store->put('k', ['count' => 3]);
        $this->assertSame(['count' => 3], $store->get('k'));

        $store->forget('k');
        $this->assertNull($store->get('k'));
        // forget on an absent key is a no-op, not an error.
        $store->forget('k');
        $this->assertNull($store->get('k'));
    }

    public function test_array_store_is_a_state_store(): void
    {
        $this->assertInstanceOf(StateStore::class, new ArrayStateStore());
    }

    public function test_array_store_flush_and_snapshot_helpers(): void
    {
        $store = new ArrayStateStore();
        $store->put('a', ['x' => 1]);
        $store->put('b', ['y' => 2]);

        $this->assertSame(['a' => ['x' => 1], 'b' => ['y' => 2]], $store->all());

        $store->flush();
        $this->assertSame([], $store->all());
    }

    // ── SessionStateStore: bound session (the seam) ──────────────────────

    public function test_session_store_routes_through_the_bound_session(): void
    {
        Container::reset();
        $session = new Store('test_sess', new ArraySessionHandler());
        $session->start();
        Container::getInstance()->instance('session', $session);

        // A conflicting raw-superglobal value must be ignored: the store reads
        // the seam, not $_SESSION.
        $_SESSION['leaked'] = ['should' => 'not appear'];

        $store = new SessionStateStore();
        $store->put('widget', ['open' => true]);

        $this->assertSame(['open' => true], $store->get('widget'));
        $this->assertSame(['open' => true], $session->get('widget'), 'value must live in the session Store');
        $this->assertNull($store->get('leaked'), 'must not read $_SESSION when a session is bound');

        $store->forget('widget');
        $this->assertNull($store->get('widget'));
        $this->assertNull($session->get('widget'));

        unset($_SESSION['leaked']);
    }

    public function test_session_store_returns_null_for_non_array_values(): void
    {
        Container::reset();
        $session = new Store('test_sess', new ArraySessionHandler());
        $session->start();
        $session->put('scalar', 'not-an-array');
        Container::getInstance()->instance('session', $session);

        $this->assertNull((new SessionStateStore())->get('scalar'));
    }

    // ── SessionStateStore: no session bound (raw $_SESSION fallback) ──────

    public function test_session_store_falls_back_to_native_session_when_unbound(): void
    {
        Container::reset(); // app('session') now throws → fallback path
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $store = new SessionStateStore();
        $store->put('fallback', ['n' => 7]);

        $this->assertSame(['n' => 7], $_SESSION['fallback'], 'fallback writes go to the superglobal');
        $this->assertSame(['n' => 7], $store->get('fallback'));

        $store->forget('fallback');
        $this->assertArrayNotHasKey('fallback', $_SESSION);
    }
}
