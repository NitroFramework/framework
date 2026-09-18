<?php

namespace Tests\Unit\Session;

use Nitro\Session\RedisSessionHandler;
use PHPUnit\Framework\TestCase;

/**
 * The Redis session handler, against a stand-in for the connection.
 *
 * The stand-in records what was asked of it, so the assertions are about the
 * commands the handler issues — in particular that every write carries a TTL,
 * which is what makes idle sessions disappear without a sweep.
 */
class RedisSessionHandlerTest extends TestCase
{
    private FakeRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new FakeRedisConnection();
    }

    private function handler(int $minutes = 120, string $prefix = 'nitro:session:'): RedisSessionHandler
    {
        return new RedisSessionHandler($this->redis, $minutes, $prefix);
    }

    public function test_a_written_payload_reads_back(): void
    {
        $handler = $this->handler();

        $this->assertTrue($handler->write('abc', 'payload'));
        $this->assertSame('payload', $handler->read('abc'));
    }

    public function test_a_missing_session_reads_as_empty(): void
    {
        $this->assertSame('', $this->handler()->read('nope'));
    }

    public function test_keys_carry_the_configured_prefix(): void
    {
        $this->handler(120, 'app:sessions:')->write('abc', 'payload');

        $this->assertArrayHasKey('app:sessions:abc', $this->redis->values);
    }

    public function test_the_lifetime_becomes_the_key_expiry(): void
    {
        $this->handler(30)->write('abc', 'payload');

        $this->assertSame(1800, $this->redis->ttls['nitro:session:abc']);
    }

    /** A zero-minute lifetime must not ask Redis for a zero TTL, which it rejects. */
    public function test_an_expiry_is_never_zero(): void
    {
        $this->handler(0)->write('abc', 'payload');

        $this->assertSame(1, $this->redis->ttls['nitro:session:abc']);
    }

    public function test_destroy_removes_the_key(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'payload');

        $this->assertTrue($handler->destroy('abc'));
        $this->assertSame('', $handler->read('abc'));
    }

    /** Redis expires keys itself, so a sweep has nothing to do and costs nothing. */
    public function test_garbage_collection_does_nothing(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'payload');

        $this->assertSame(0, $handler->gc(0, 100));
        $this->assertSame('payload', $handler->read('abc'));
    }

    public function test_open_and_close_succeed(): void
    {
        $handler = $this->handler();

        $this->assertTrue($handler->open('', 'nitro_session'));
        $this->assertTrue($handler->close());
    }
}

