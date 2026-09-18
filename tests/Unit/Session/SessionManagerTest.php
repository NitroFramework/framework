<?php

namespace Tests\Unit\Session;

use InvalidArgumentException;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Handlers\DatabaseSessionHandler;
use Nitro\Session\Handlers\FileSessionHandler;
use Nitro\Session\Handlers\RedisSessionHandler;
use Nitro\Session\NativeSession;
use Nitro\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which backend a configured driver name resolves to.
 */
class SessionManagerTest extends TestCase
{
    private function manager(array $config = [], ?callable $redis = null): SessionManager
    {
        return new SessionManager($config + [
            'cookie'   => 'nitro_session',
            'lifetime' => 120,
            'files'    => sys_get_temp_dir() . '/nitro-session-test',
        ], $redis === null ? null : \Closure::fromCallable($redis));
    }

    public function test_the_native_driver_has_no_handler(): void
    {
        $this->assertInstanceOf(NativeSession::class, $this->manager(['driver' => 'native'])->driver());
    }

    public function test_the_file_driver(): void
    {
        $this->assertInstanceOf(
            FileSessionHandler::class,
            $this->manager(['driver' => 'file'])->driver()->getHandler(),
        );
    }

    public function test_the_array_driver(): void
    {
        $this->assertInstanceOf(
            ArraySessionHandler::class,
            $this->manager(['driver' => 'array'])->driver()->getHandler(),
        );
    }

    public function test_the_database_driver(): void
    {
        $this->assertInstanceOf(
            DatabaseSessionHandler::class,
            $this->manager(['driver' => 'database'])->driver()->getHandler(),
        );
    }

    public function test_the_redis_driver_is_given_the_resolved_connection(): void
    {
        $connection = new FakeRedisConnection();
        $asked = null;

        $manager = $this->manager(
            ['driver' => 'redis', 'connection' => 'sessions'],
            function (?string $name) use ($connection, &$asked): object {
                $asked = $name;

                return $connection;
            },
        );

        $this->assertInstanceOf(RedisSessionHandler::class, $manager->driver()->getHandler());
        $this->assertSame('sessions', $asked);
    }

    /** Choosing redis without the Redis layer registered must say so, not fail later. */
    public function test_the_redis_driver_without_a_resolver_explains_itself(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis connection resolver');

        $this->manager(['driver' => 'redis'])->driver();
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('memcached');

        $this->manager(['driver' => 'memcached'])->driver();
    }

    public function test_a_driver_can_be_asked_for_by_name(): void
    {
        $this->assertInstanceOf(
            ArraySessionHandler::class,
            $this->manager(['driver' => 'file'])->driver('array')->getHandler(),
        );
    }

    /**
     * The binding that holds a Store is scoped in the container, which is where
     * per-request caching belongs; memoizing here would outlive the request.
     */
    public function test_each_call_builds_a_fresh_store(): void
    {
        $manager = $this->manager(['driver' => 'array']);

        $this->assertNotSame($manager->driver(), $manager->driver());
    }
}
