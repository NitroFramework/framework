<?php

namespace Tests\Unit\Broadcasting;

use Nitro\Broadcasting\BroadcastException;
use Nitro\Broadcasting\Drivers\PusherBroadcaster;
use Nitro\Broadcasting\Drivers\RedisBroadcaster;
use Nitro\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * The two drivers that actually reach a client.
 *
 * Until these, broadcasting had only log and null — one writes to a file and
 * the other discards, so an application could broadcast all day and nothing
 * ever arrived anywhere. Both of these speak their protocol over what the
 * framework already ships rather than adding a dependency.
 */
class BroadcastDriverTest extends TestCase
{
    // ─── Redis ────────────────────────────────────────────

    /** A recording stand-in for the Redis connection. */
    private function redis(array &$published): \Closure
    {
        $connection = new class ($published) {
            /** @param array<int, array{0: string, 1: array<int, string>}> $published */
            public function __construct(public array &$published) {}

            public function command(string $method, array $parameters = []): mixed
            {
                $this->published[] = [$method, $parameters];

                return 1;
            }
        };

        return static fn (): object => $connection;
    }

    public function test_redis_publishes_one_message_per_channel(): void
    {
        $published = [];

        $broadcaster = new RedisBroadcaster($this->redis($published));

        $broadcaster->broadcast(['private-rooms.1', 'private-rooms.2'], 'RoomMessage', ['body' => 'hi']);

        $this->assertCount(2, $published);
        $this->assertSame('publish', $published[0][0]);
        $this->assertSame('private-rooms.1', $published[0][1][0]);
        $this->assertSame('private-rooms.2', $published[1][1][0]);
    }

    /** The shape a relay expects: the name, the data, and who to skip. */
    public function test_redis_sends_the_shape_a_relay_reads(): void
    {
        $published = [];

        $broadcaster = new RedisBroadcaster($this->redis($published));

        $broadcaster->broadcast(['rooms'], 'RoomMessage', ['body' => 'hi', 'socket' => 'abc']);

        $message = json_decode($published[0][1][1], true);

        $this->assertSame('RoomMessage', $message['event']);
        $this->assertSame(['body' => 'hi'], $message['data'], 'the socket is not part of the data');
        $this->assertSame('abc', $message['socket']);
    }

    public function test_redis_can_prefix_its_channels(): void
    {
        $published = [];

        $broadcaster = new RedisBroadcaster($this->redis($published), 'app-db0:');

        $broadcaster->broadcast(['rooms'], 'RoomMessage');

        $this->assertSame('app-db0:rooms', $published[0][1][0]);
    }

    public function test_redis_with_no_channels_publishes_nothing(): void
    {
        $published = [];

        (new RedisBroadcaster($this->redis($published)))->broadcast([], 'RoomMessage');

        $this->assertSame([], $published);
    }

    // ─── Pusher ───────────────────────────────────────────

    /** @param array<string, mixed> $config */
    private function pusher(HttpFactory $http, array $config = []): PusherBroadcaster
    {
        return new PusherBroadcaster($config + [
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => '12345',
        ], $http);
    }

    public function test_pusher_refuses_to_build_without_credentials(): void
    {
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('[secret]');

        new PusherBroadcaster(['key' => 'k', 'app_id' => '1']);
    }

    public function test_pusher_posts_the_event_to_the_events_endpoint(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http)->broadcast(['private-rooms.1'], 'RoomMessage', ['body' => 'hi']);

        $http->assertSent(function (array $request): bool {
            $this->assertStringContainsString('/apps/12345/events', (string) $request['url']);

            $body = json_decode((string) $request['body'], true);

            $this->assertSame('RoomMessage', $body['name']);
            $this->assertSame(['private-rooms.1'], $body['channels']);
            $this->assertSame(['body' => 'hi'], json_decode($body['data'], true));

            return true;
        });
    }

    /** Signed so neither the body nor the parameters can be altered in flight. */
    public function test_pusher_signs_the_request(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http)->broadcast(['rooms'], 'RoomMessage');

        $http->assertSent(function (array $request): bool {
            $url = (string) $request['url'];

            $this->assertStringContainsString('auth_key=app-key', $url);
            $this->assertStringContainsString('auth_signature=', $url);
            $this->assertStringContainsString('body_md5=', $url);
            $this->assertStringContainsString('auth_version=1.0', $url);

            return true;
        });
    }

    public function test_pusher_passes_the_socket_to_exclude(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http)->broadcast(['rooms'], 'RoomMessage', ['body' => 'hi', 'socket' => 'abc']);

        $http->assertSent(function (array $request): bool {
            $body = json_decode((string) $request['body'], true);

            $this->assertSame('abc', $body['socket_id']);
            $this->assertSame(['body' => 'hi'], json_decode($body['data'], true));

            return true;
        });
    }

    public function test_pusher_uses_the_cluster_for_the_hosted_service(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http, ['cluster' => 'eu'])->broadcast(['rooms'], 'RoomMessage');

        $http->assertSent(static fn (array $request): bool
            => str_starts_with((string) $request['url'], 'https://api-eu.pusher.com'));
    }

    /** A self-hosted, Pusher-compatible server instead. */
    public function test_pusher_can_point_at_your_own_server(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http, ['host' => 'sockets.example.test', 'port' => 6001, 'scheme' => 'http'])
            ->broadcast(['rooms'], 'RoomMessage');

        $http->assertSent(static fn (array $request): bool
            => str_starts_with((string) $request['url'], 'http://sockets.example.test:6001'));
    }

    public function test_pusher_reports_a_refusal_rather_than_swallowing_it(): void
    {
        $http = new HttpFactory();
        $http->fake(fn () => $http->response('app disabled', 401));

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('401');

        $this->pusher($http)->broadcast(['rooms'], 'RoomMessage');
    }

    public function test_pusher_with_no_channels_sends_nothing(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $this->pusher($http)->broadcast([], 'RoomMessage');

        $http->assertNothingSent();
    }

    // ─── Signing a client in ──────────────────────────────

    /**
     * What a client hands the socket server to prove it may subscribe. The
     * key is public; the signature is what cannot be forged.
     */
    public function test_pusher_signs_a_private_channel_subscription(): void
    {
        $auth = $this->pusher(new HttpFactory())->authFor('1234.5678', 'private-rooms.1');

        $this->assertSame(
            'app-key:' . hash_hmac('sha256', '1234.5678:private-rooms.1', 'app-secret'),
            $auth['auth'],
        );

        $this->assertArrayNotHasKey('channel_data', $auth);
    }

    /** A presence channel also carries who is joining, and signs that too. */
    public function test_pusher_signs_a_presence_channel_with_its_member(): void
    {
        $member = ['user_id' => 7, 'user_info' => ['name' => 'Ada']];

        $auth = $this->pusher(new HttpFactory())->authFor('1234.5678', 'presence-lobby', $member);

        $encoded = json_encode($member, JSON_UNESCAPED_SLASHES);

        $this->assertSame($encoded, $auth['channel_data']);

        $this->assertSame(
            'app-key:' . hash_hmac('sha256', '1234.5678:presence-lobby:' . $encoded, 'app-secret'),
            $auth['auth'],
        );
    }

    public function test_a_different_socket_gets_a_different_signature(): void
    {
        $pusher = $this->pusher(new HttpFactory());

        $this->assertNotSame(
            $pusher->authFor('1111.1111', 'private-rooms.1')['auth'],
            $pusher->authFor('2222.2222', 'private-rooms.1')['auth'],
        );
    }
}
