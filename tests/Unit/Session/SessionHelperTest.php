<?php

namespace Tests\Unit\Session;

use Nitro\Container\Container;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The session() helper's read and write forms.
 *
 * session('key', $default) used to WRITE the default and return it, which made
 * every ordinary read-with-fallback a destructive operation: session('basket',
 * []) emptied the basket instead of returning one, on every request, with no
 * error anywhere.
 */
class SessionHelperTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();

        $this->store = new Store('nitro_session', new \Nitro\Session\ArraySessionHandler());
        $this->store->start();

        Container::getInstance()->instance('session', $this->store);
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function test_a_missing_key_returns_the_default_without_storing_it(): void
    {
        $this->assertSame([], session('basket', []));

        // The important half: asking did not write.
        $this->assertFalse($this->store->has('basket'));
    }

    public function test_a_present_key_returns_its_value_not_the_default(): void
    {
        $this->store->put('basket', [7 => ['quantity' => 3]]);

        $this->assertSame([7 => ['quantity' => 3]], session('basket', []));
    }

    public function test_a_read_with_a_default_does_not_overwrite_a_stored_value(): void
    {
        $this->store->put('basket', ['kept']);

        session('basket', []);

        $this->assertSame(['kept'], $this->store->get('basket'));
    }

    public function test_a_missing_key_with_no_default_is_null(): void
    {
        $this->assertNull(session('nothing-here'));
    }

    public function test_an_array_writes_pairs(): void
    {
        session(['basket' => ['written'], 'other' => 2]);

        $this->assertSame(['written'], $this->store->get('basket'));
        $this->assertSame(2, $this->store->get('other'));
    }

    public function test_no_argument_yields_the_store(): void
    {
        session()->put('basket', ['via the store']);

        $this->assertSame(['via the store'], $this->store->get('basket'));
    }
}
