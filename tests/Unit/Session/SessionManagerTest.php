<?php

namespace Tests\Unit\Session;

use InvalidArgumentException;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use Nitro\Cookie\CookieJar;
use Nitro\Encryption\Encrypter;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\CacheBasedSessionHandler;
use Nitro\Session\CookieSessionHandler;
use Nitro\Session\DatabaseSessionHandler;
use Nitro\Session\EncryptedStore;
use Nitro\Session\FileSessionHandler;
use Nitro\Session\NativeSession;
use Nitro\Session\NullSessionHandler;
use Nitro\Session\RedisSessionHandler;
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

    public function test_the_null_driver(): void
    {
        $this->assertInstanceOf(
            NullSessionHandler::class,
            $this->manager(['driver' => 'null'])->driver()->getHandler(),
        );
    }

    /**
     * Laravel's own default is 'cookie', and a platform that assumes Laravel
     * will inject it — so the driver has to exist, not just be rejected well.
     */
    public function test_the_cookie_driver(): void
    {
        $manager = new SessionManager(
            ['driver' => 'cookie', 'cookie' => 'nitro_session', 'lifetime' => 120],
            null,
            fn (): CookieJar => new CookieJar(),
        );

        $this->assertInstanceOf(CookieSessionHandler::class, $manager->driver()->getHandler());
    }

    public function test_the_cache_driver_is_handed_the_configured_store(): void
    {
        $asked = null;

        $manager = new SessionManager(
            ['driver' => 'cache', 'store' => 'redis', 'cookie' => 'nitro_session', 'lifetime' => 120],
            null,
            null,
            function (string $store) use (&$asked): Repository {
                $asked = $store;

                return new Repository(new ArrayStore());
            },
        );

        $this->assertInstanceOf(CacheBasedSessionHandler::class, $manager->driver()->getHandler());
        $this->assertSame('redis', $asked);
    }

    public function test_encrypt_wraps_the_store(): void
    {
        $manager = new SessionManager(
            ['driver' => 'array', 'encrypt' => true, 'cookie' => 'nitro_session', 'lifetime' => 120],
            null,
            null,
            null,
            fn (): Encrypter => new Encrypter(str_repeat('a', 32)),
        );

        $this->assertInstanceOf(EncryptedStore::class, $manager->driver());
    }

    public function test_a_store_is_plain_unless_encryption_is_asked_for(): void
    {
        $this->assertNotInstanceOf(EncryptedStore::class, $this->manager(['driver' => 'array'])->driver());
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
        $this->expectExceptionMessage('The redis session driver needs the Redis service provider');

        $this->manager(['driver' => 'redis'])->driver();
    }

    /** Every driver with a backing layer names the provider it is missing. */
    public function test_each_backed_driver_without_a_resolver_explains_itself(): void
    {
        foreach (['cookie' => 'Cookie', 'cache' => 'Cache'] as $driver => $provider) {
            try {
                $this->manager(['driver' => $driver])->driver();
                $this->fail("expected [{$driver}] to raise without its resolver");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    "The {$driver} session driver needs the {$provider} service provider",
                    $exception->getMessage()
                );
            }
        }
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
