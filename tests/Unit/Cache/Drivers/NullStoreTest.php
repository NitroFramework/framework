<?php

namespace Tests\Unit\Cache\Drivers;

use Nitro\Cache\Drivers\NullStore;
use PHPUnit\Framework\TestCase;

class NullStoreTest extends TestCase
{
    protected NullStore $store;

    protected function setUp(): void
    {
        $this->store = new NullStore();
    }

    public function test_get_always_returns_null(): void
    {
        $this->store->put('key', 'value', 3600);

        $this->assertNull($this->store->get('key'));
    }

    public function test_many_returns_all_nulls(): void
    {
        $result = $this->store->many(['a', 'b', 'c']);

        $this->assertSame(['a' => null, 'b' => null, 'c' => null], $result);
    }

    public function test_put_returns_false(): void
    {
        $this->assertFalse($this->store->put('key', 'value', 3600));
    }

    public function test_put_many_returns_false(): void
    {
        $this->assertFalse($this->store->putMany(['a' => 1, 'b' => 2], 3600));
    }

    public function test_increment_returns_false(): void
    {
        $this->assertFalse($this->store->increment('counter'));
    }

    public function test_decrement_returns_false(): void
    {
        $this->assertFalse($this->store->decrement('counter'));
    }

    public function test_forever_returns_false(): void
    {
        $this->assertFalse($this->store->forever('key', 'value'));
    }

    public function test_forget_returns_false(): void
    {
        $this->assertFalse($this->store->forget('key'));
    }

    public function test_flush_returns_true(): void
    {
        $this->assertTrue($this->store->flush());
    }

    public function test_prefix_is_empty(): void
    {
        $this->assertSame('', $this->store->getPrefix());
    }
}