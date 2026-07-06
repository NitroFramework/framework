<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\Repository;
use Nitro\Cache\Drivers\ArrayStore;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    protected Repository $cache;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
    }

    // -------------------------------------------------------------------------
    // Has
    // -------------------------------------------------------------------------

    public function test_has_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->cache->has('missing'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->cache->put('exists', 'yes');

        $this->assertTrue($this->cache->has('exists'));
    }

    // -------------------------------------------------------------------------
    // Get with Default
    // -------------------------------------------------------------------------

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function test_get_returns_value_when_present(): void
    {
        $this->cache->put('key', 'value');

        $this->assertSame('value', $this->cache->get('key', 'fallback'));
    }

    public function test_get_default_is_null(): void
    {
        $this->assertNull($this->cache->get('missing'));
    }

    // -------------------------------------------------------------------------
    // Put
    // -------------------------------------------------------------------------

    public function test_put_stores_value_with_default_ttl(): void
    {
        $this->cache->put('key', 'value');

        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_put_stores_value_with_custom_ttl(): void
    {
        $this->cache->put('key', 'value', 60);

        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_put_with_zero_ttl_removes_key(): void
    {
        $this->cache->put('key', 'value', 3600);
        $this->cache->put('key', 'new', 0);

        $this->assertNull($this->cache->get('key'));
    }

    public function test_put_with_negative_ttl_removes_key(): void
    {
        $this->cache->put('key', 'value', 3600);
        $this->cache->put('key', 'new', -1);

        $this->assertNull($this->cache->get('key'));
    }

    // -------------------------------------------------------------------------
    // Many
    // -------------------------------------------------------------------------

    public function test_many_with_numeric_keys(): void
    {
        $this->cache->put('a', 1);
        $this->cache->put('b', 2);

        $result = $this->cache->many(['a', 'b', 'c']);

        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertNull($result['c']);
    }

    public function test_many_with_default_values(): void
    {
        $this->cache->put('a', 1);

        $result = $this->cache->many(['a' => 'default_a', 'b' => 'default_b']);

        $this->assertSame(1, $result['a']);
        $this->assertSame('default_b', $result['b']);
    }

    public function test_put_many(): void
    {
        $this->cache->putMany(['x' => 10, 'y' => 20]);

        $this->assertSame(10, $this->cache->get('x'));
        $this->assertSame(20, $this->cache->get('y'));
    }

    // -------------------------------------------------------------------------
    // Forever
    // -------------------------------------------------------------------------

    public function test_forever_stores_value(): void
    {
        $this->cache->forever('eternal', 'always');

        $this->assertSame('always', $this->cache->get('eternal'));
    }

    // -------------------------------------------------------------------------
    // Remember
    // -------------------------------------------------------------------------

    public function test_remember_returns_cached_value_on_hit(): void
    {
        $this->cache->put('key', 'cached');
        $called = false;

        $result = $this->cache->remember('key', 3600, function () use (&$called) {
            $called = true;
            return 'fresh';
        });

        $this->assertSame('cached', $result);
        $this->assertFalse($called, 'Callback should not be called on cache hit');
    }

    public function test_remember_executes_callback_on_miss(): void
    {
        $called = false;

        $result = $this->cache->remember('key', 3600, function () use (&$called) {
            $called = true;
            return 'computed';
        });

        $this->assertSame('computed', $result);
        $this->assertTrue($called);
    }

    public function test_remember_stores_callback_result(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'expensive_result';
        };

        // First call: cache miss, callback runs
        $this->cache->remember('key', 3600, $callback);
        $this->assertSame(1, $callCount);

        // Second call: cache hit, callback does NOT run
        $result = $this->cache->remember('key', 3600, $callback);
        $this->assertSame(1, $callCount);
        $this->assertSame('expensive_result', $result);
    }

    public function test_remember_forever(): void
    {
        $result = $this->cache->rememberForever('key', fn() => 'permanent');

        $this->assertSame('permanent', $result);
        $this->assertSame('permanent', $this->cache->get('key'));
    }

    // -------------------------------------------------------------------------
    // Pull
    // -------------------------------------------------------------------------

    public function test_pull_returns_value_and_removes_it(): void
    {
        $this->cache->put('flash', 'message');

        $result = $this->cache->pull('flash');

        $this->assertSame('message', $result);
        $this->assertNull($this->cache->get('flash'));
    }

    public function test_pull_returns_default_when_missing(): void
    {
        $result = $this->cache->pull('missing', 'default');

        $this->assertSame('default', $result);
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    public function test_increment(): void
    {
        $this->assertSame(1, $this->cache->increment('counter'));
        $this->assertSame(2, $this->cache->increment('counter'));
        $this->assertSame(7, $this->cache->increment('counter', 5));
    }

    public function test_decrement(): void
    {
        $this->cache->put('counter', 10);

        $this->assertSame(9, $this->cache->decrement('counter'));
        $this->assertSame(4, $this->cache->decrement('counter', 5));
    }

    // -------------------------------------------------------------------------
    // Forget / Flush
    // -------------------------------------------------------------------------

    public function test_forget_removes_single_key(): void
    {
        $this->cache->put('a', 1);
        $this->cache->put('b', 2);

        $this->cache->forget('a');

        $this->assertNull($this->cache->get('a'));
        $this->assertSame(2, $this->cache->get('b'));
    }

    public function test_flush_removes_everything(): void
    {
        $this->cache->put('a', 1);
        $this->cache->put('b', 2);

        $this->cache->flush();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }

    // -------------------------------------------------------------------------
    // Tags
    // -------------------------------------------------------------------------

    public function test_tags_throws_on_non_taggable_store(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support tagging');

        $this->cache->tags('invoices');
    }

    // -------------------------------------------------------------------------
    // Null TTL = forever (Laravel / PSR-16)
    // -------------------------------------------------------------------------

    public function test_put_without_ttl_stores_forever(): void
    {
        $this->cache->put('perm', 'v');
        $this->assertSame('v', $this->cache->get('perm'));
    }

    public function test_remember_with_null_ttl_stores_forever(): void
    {
        $this->cache->remember('r', null, fn() => 'computed');
        $this->assertSame('computed', $this->cache->get('r'));
    }

    // -------------------------------------------------------------------------
    // Store Access
    // -------------------------------------------------------------------------

    public function test_get_store_returns_underlying_driver(): void
    {
        $this->assertInstanceOf(ArrayStore::class, $this->cache->getStore());
    }
}