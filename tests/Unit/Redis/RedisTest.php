<?php

namespace Tests\Unit\Redis;

use InvalidArgumentException;
use Nitro\Redis\Connections\PhpRedisConnection;
use Nitro\Redis\RedisManager;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Redis client against a real server. Skipped when phpredis is
 * missing or no server is reachable on 127.0.0.1:6379, so CI without Redis stays
 * green; run `docker run -d -p 6379:6379 redis` to include these.
 */
class RedisTest extends TestCase
{
    private function manager(): RedisManager
    {
        return new RedisManager([
            'default' => 'default',
            'connections' => [
                'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 15],
                'other'   => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 14],
            ],
        ]);
    }

    protected function setUp(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension required');
        }
        $socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);
        if (! $socket) {
            $this->markTestSkipped('no Redis server on 127.0.0.1:6379');
        }
        fclose($socket);

        $this->manager()->connection()->flushDB();
        $this->manager()->connection('other')->flushDB();
    }

    public function test_connection_resolves_and_is_cached(): void
    {
        $manager = $this->manager();

        $this->assertInstanceOf(PhpRedisConnection::class, $manager->connection());
        $this->assertSame($manager->connection(), $manager->connection(), 'same instance is reused');
    }

    public function test_set_get_del_via_proxy(): void
    {
        $manager = $this->manager();

        $manager->set('name', 'ada');
        $this->assertSame('ada', $manager->get('name'));

        $manager->del('name');
        $this->assertFalse($manager->get('name'));
    }

    public function test_expire_and_ttl(): void
    {
        $manager = $this->manager();
        $manager->set('temp', '1');
        $manager->expire('temp', 100);

        $this->assertGreaterThan(0, $manager->ttl('temp'));
    }

    public function test_hashes_and_lists(): void
    {
        $conn = $this->manager()->connection();

        $conn->hset('user:1', 'name', 'Ada');
        $this->assertSame('Ada', $conn->hget('user:1', 'name'));

        $conn->rpush('queue', 'a', 'b');
        $this->assertSame(2, $conn->llen('queue'));
        $this->assertSame('a', $conn->lpop('queue'));
    }

    public function test_named_connections_are_isolated_by_database(): void
    {
        $manager = $this->manager();
        $manager->connection('default')->set('k', 'in-db-15');

        // The 'other' connection points at a different database.
        $this->assertFalse($manager->connection('other')->get('k'));
    }

    public function test_command_by_name(): void
    {
        $manager = $this->manager();
        $manager->connection()->command('set', ['key-via-command', 'stored']);

        $this->assertSame('stored', $manager->connection()->command('get', ['key-via-command']));
    }

    public function test_unknown_connection_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager()->connection('ghost');
    }
}
