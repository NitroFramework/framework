<?php

namespace Tests\Unit\Redis;

use InvalidArgumentException;
use Nitro\Redis\Contracts\Connection;
use Nitro\Redis\RedisManager;
use PHPUnit\Framework\TestCase;

/**
 * Registering a driver of one's own, and swapping a connection out.
 *
 * The manager built one class and there was no way in, so an application
 * wanting Predis, a connection over a socket, or a stand-in for a test had
 * nowhere to put it. These need no server: that is the point of them.
 */
class RedisManagerTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $connections */
    private function manager(array $connections = ['main' => ['driver' => 'recording']]): RedisManager
    {
        return new RedisManager(['default' => 'main', 'connections' => $connections]);
    }

    public function test_a_driver_of_your_own_can_be_registered(): void
    {
        $manager = $this->manager();
        $manager->extend('recording', static fn (): Connection => new RecordingRedisConnection());

        $this->assertInstanceOf(RecordingRedisConnection::class, $manager->connection());
    }

    public function test_the_registered_driver_receives_the_commands(): void
    {
        $manager = $this->manager();
        $manager->extend('recording', static fn (): Connection => new RecordingRedisConnection());

        $manager->connection()->command('publish', ['channel', 'message']);

        $this->assertSame([['publish', ['channel', 'message']]], $manager->connection()->calls);
    }

    public function test_the_factory_is_told_which_connection_it_is_building(): void
    {
        $seen = '';

        $manager = $this->manager();
        $manager->extend('recording', static function (array $config, string $name) use (&$seen): Connection {
            $seen = $name;

            return new RecordingRedisConnection();
        });

        $manager->connection();

        $this->assertSame('main', $seen);
    }

    public function test_connections_lists_only_what_has_been_resolved(): void
    {
        $manager = $this->manager([
            'main' => ['driver' => 'recording'],
            'other' => ['driver' => 'recording'],
        ]);

        $manager->extend('recording', static fn (): Connection => new RecordingRedisConnection());

        $this->assertSame([], array_keys($manager->connections()));

        $manager->connection('main');

        $this->assertSame(['main'], array_keys($manager->connections()));
    }

    public function test_set_connection_puts_one_in_place_without_a_driver(): void
    {
        $manager = $this->manager([]);
        $manager->setConnection('main', new RecordingRedisConnection());

        $this->assertInstanceOf(RecordingRedisConnection::class, $manager->connection());
    }

    public function test_an_unconfigured_connection_names_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nope/');

        (new RedisManager(['default' => 'nope', 'connections' => []]))->connection();
    }
}

/** A connection that records what it was asked, to prove extend() reaches it. */
class RecordingRedisConnection implements Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public function command(string $method, array $parameters = []): mixed
    {
        $this->calls[] = [$method, $parameters];

        return 'recorded';
    }

    public function client(): object
    {
        return $this;
    }
}
