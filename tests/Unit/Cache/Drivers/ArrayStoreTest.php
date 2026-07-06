<?php

namespace Tests\Unit\Cache\Drivers;

use Nitro\Cache\Drivers\ArrayStore;
use PHPUnit\Framework\TestCase;

class ArrayStoreTest extends TestCase
{
    protected ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
    }

    // -------------------------------------------------------------------------
    // Get / Put
    // -------------------------------------------------------------------------

    public function test_it_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function test_it_can_store_and_retrieve_a_value(): void
    {
        $this->store->put('name', 'Mirza', 3600);

        $this->assertSame('Mirza', $this->store->get('name'));
    }

    public function test_it_can_store_arrays(): void
    {
        $data = ['id' => 1, 'name' => 'Student'];
        $this->store->put('student', $data, 3600);

        $this->assertSame($data, $this->store->get('student'));
    }

    public function test_it_can_store_objects(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';

        $this->store->put('obj', $obj, 3600);

        $this->assertEquals($obj, $this->store->get('obj'));
    }

    public function test_it_can_store_boolean_false(): void
    {
        $this->store->put('flag', false, 3600);

        // false is a valid value, should not return null
        $this->assertFalse($this->store->get('flag'));
    }

    public function test_it_can_store_integer_zero(): void
    {
        $this->store->put('count', 0, 3600);

        $this->assertSame(0, $this->store->get('count'));
    }

    public function test_it_can_store_empty_string(): void
    {
        $this->store->put('empty', '', 3600);

        $this->assertSame('', $this->store->get('empty'));
    }

    public function test_it_can_store_null_value(): void
    {
        $this->store->put('nothing', null, 3600);

        // null stored = null retrieved, same as missing key
        $this->assertNull($this->store->get('nothing'));
    }

    // -------------------------------------------------------------------------
    // Many
    // -------------------------------------------------------------------------

    public function test_it_can_get_many_values(): void
    {
        $this->store->put('a', 1, 3600);
        $this->store->put('b', 2, 3600);

        $result = $this->store->many(['a', 'b', 'c']);

        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertNull($result['c']);
    }

    public function test_it_can_put_many_values(): void
    {
        $this->store->putMany(['x' => 10, 'y' => 20, 'z' => 30], 3600);

        $this->assertSame(10, $this->store->get('x'));
        $this->assertSame(20, $this->store->get('y'));
        $this->assertSame(30, $this->store->get('z'));
    }

    // -------------------------------------------------------------------------
    // Expiration
    // -------------------------------------------------------------------------

    public function test_expired_items_return_null(): void
    {
        // Store with 0-second TTL (already expired)
        $this->store->put('expired', 'value', 0);

        $this->assertNull($this->store->get('expired'));
    }

    public function test_items_with_future_expiry_are_accessible(): void
    {
        $this->store->put('valid', 'data', 9999);

        $this->assertSame('data', $this->store->get('valid'));
    }

    // -------------------------------------------------------------------------
    // Forever
    // -------------------------------------------------------------------------

    public function test_forever_stores_without_expiration(): void
    {
        $this->store->forever('persistent', 'always here');

        $this->assertSame('always here', $this->store->get('persistent'));
    }

    // -------------------------------------------------------------------------
    // Forget
    // -------------------------------------------------------------------------

    public function test_forget_removes_a_key(): void
    {
        $this->store->put('temp', 'data', 3600);
        $this->store->forget('temp');

        $this->assertNull($this->store->get('temp'));
    }

    public function test_forget_returns_true_for_existing_key(): void
    {
        $this->store->put('exists', 'yes', 3600);

        $this->assertTrue($this->store->forget('exists'));
    }

    public function test_forget_returns_true_for_missing_key(): void
    {
        // ArrayStore always returns true on forget
        $this->assertTrue($this->store->forget('nope'));
    }

    // -------------------------------------------------------------------------
    // Flush
    // -------------------------------------------------------------------------

    public function test_flush_removes_all_items(): void
    {
        $this->store->put('a', 1, 3600);
        $this->store->put('b', 2, 3600);
        $this->store->forever('c', 3);

        $this->store->flush();

        $this->assertNull($this->store->get('a'));
        $this->assertNull($this->store->get('b'));
        $this->assertNull($this->store->get('c'));
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    public function test_increment_creates_key_if_missing(): void
    {
        $result = $this->store->increment('counter');

        $this->assertSame(1, $result);
        $this->assertSame(1, $this->store->get('counter'));
    }

    public function test_increment_by_custom_value(): void
    {
        $this->store->put('counter', 10, 3600);

        $result = $this->store->increment('counter', 5);

        $this->assertSame(15, $result);
    }

    public function test_decrement_works(): void
    {
        $this->store->put('counter', 10, 3600);

        $result = $this->store->decrement('counter', 3);

        $this->assertSame(7, $result);
    }

    public function test_decrement_can_go_negative(): void
    {
        $this->store->put('counter', 2, 3600);

        $result = $this->store->decrement('counter', 5);

        $this->assertSame(-3, $result);
    }

    // -------------------------------------------------------------------------
    // Prefix
    // -------------------------------------------------------------------------

    public function test_prefix_is_applied_to_keys(): void
    {
        $store = new ArrayStore('app:');

        $store->put('key', 'value', 3600);

        $this->assertSame('value', $store->get('key'));
        $this->assertSame('app:', $store->getPrefix());
    }

    public function test_default_prefix_is_empty(): void
    {
        $this->assertSame('', $this->store->getPrefix());
    }

    // -------------------------------------------------------------------------
    // Overwrite
    // -------------------------------------------------------------------------

    public function test_put_overwrites_existing_value(): void
    {
        $this->store->put('key', 'first', 3600);
        $this->store->put('key', 'second', 3600);

        $this->assertSame('second', $this->store->get('key'));
    }
}