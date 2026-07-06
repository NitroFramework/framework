<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * putMany() with a non-positive TTL must delete the keys (expire now), the same
 * as put() — not silently leave stale values in place while returning false.
 */
class PutManyExpiryTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
    }

    public function test_put_many_with_zero_ttl_removes_existing_values(): void
    {
        $this->cache->put('a', 1);
        $this->cache->put('b', 2);

        $this->cache->putMany(['a' => 10, 'b' => 20], 0);

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function test_put_many_with_positive_ttl_still_stores(): void
    {
        $this->cache->putMany(['x' => 1, 'y' => 2], 60);

        $this->assertSame(1, $this->cache->get('x'));
        $this->assertSame(2, $this->cache->get('y'));
    }
}
