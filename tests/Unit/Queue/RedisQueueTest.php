<?php

namespace Tests\Unit\Queue;

use Nitro\Redis\RedisManager;

/**
 * The Redis queue driver against a real server.
 *
 * Skipped when phpredis is missing or nothing answers on 127.0.0.1:6379, so a
 * machine without Redis stays green; run `docker run -d -p 6379:6379 redis` to
 * include these.
 */
class RedisQueueTest extends RedisQueueTestCase
{
    protected function makeConnection(): object
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension required');
        }

        $socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

        if (! $socket) {
            $this->markTestSkipped('no Redis server on 127.0.0.1:6379');
        }

        fclose($socket);

        $connection = (new RedisManager([
            'default' => 'default',
            'connections' => [
                'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 13],
            ],
        ]))->connection();

        $connection->flushDB();

        return $connection;
    }
}
