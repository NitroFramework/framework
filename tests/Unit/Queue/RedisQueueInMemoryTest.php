<?php

namespace Tests\Unit\Queue;

/**
 * The Redis queue driver against an in-memory double — the bookkeeping, on any
 * machine. {@see RedisQueueTest} runs the same checks against a real server.
 */
class RedisQueueInMemoryTest extends RedisQueueTestCase
{
    protected function makeConnection(): object
    {
        return new FakeRedis();
    }
}
