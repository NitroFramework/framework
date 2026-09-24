<?php

namespace Tests\Unit\Broadcasting;

use Nitro\Broadcasting\BroadcastManager;
use Nitro\Broadcasting\Channel;
use Nitro\Broadcasting\Contracts\Broadcaster;
use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Broadcasting\Contracts\ShouldBroadcastNow;
use Nitro\Broadcasting\Drivers\FakeBroadcaster;
use Nitro\Broadcasting\EncryptedPrivateChannel;
use Nitro\Broadcasting\InteractsWithBroadcasting;
use Nitro\Broadcasting\InteractsWithSockets;
use Nitro\Broadcasting\PresenceChannel;
use Nitro\Broadcasting\PrivateChannel;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Dispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * An event reaching listening clients because it was dispatched.
 *
 * The ShouldBroadcast contract marked events and nothing read the mark: no
 * dispatch path called broadcastOn(), so firing a broadcastable event sent it
 * to its listeners and nowhere else. These cover the path from dispatch to
 * driver, which is the one an application actually uses.
 */
class BroadcastEventFlowTest extends TestCase
{
    private Container $container;

    private BroadcastManager $broadcast;

    private FakeBroadcaster $fake;

    private Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();

        $this->container = new Container();

        Container::setInstance($this->container);

        $this->container->instance(ConfigRepository::class, new class implements ConfigRepository {
            public function has(string $key): bool { return false; }
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function all(): array { return []; }
            public function set(string $key, mixed $value): void {}
        });

        $this->broadcast = new BroadcastManager(
            $this->container->resolve(ClassResolver::class),
            $this->container->resolve(ConfigRepository::class),
        );

        $this->fake = new FakeBroadcaster();

        $this->broadcast->extend('fake', fn (): Broadcaster => $this->fake);
        $this->broadcast->setDefaultDriver('fake');

        $this->container->instance(BroadcastManager::class, $this->broadcast);
        $this->container->instance('broadcast', $this->broadcast);

        $this->events = new Dispatcher();
        $this->events->setContainer($this->container);
        $this->events->setBroadcastResolver(fn (): object => $this->broadcast);
    }

    protected function tearDown(): void
    {
        // A fresh one rather than reset(): leaving no container at all makes
        // the next test file's first app() call fail, wherever it happens to
        // run in the order.
        Container::setInstance(new Container());

        parent::tearDown();
    }

    // ─── The contract is read ─────────────────────────────

    /** The defect: this sent the event to listeners and nowhere else. */
    public function test_dispatching_a_broadcastable_event_broadcasts_it(): void
    {
        $this->events->dispatch(new RoomMessage('hello', 7));

        $this->fake->assertBroadcast(RoomMessage::class, 'private-rooms.7');
    }

    public function test_an_ordinary_event_is_not_broadcast(): void
    {
        $this->events->dispatch(new NotBroadcastable());

        $this->fake->assertNothingBroadcast();
    }

    public function test_listeners_still_run_for_a_broadcastable_event(): void
    {
        $heard = [];

        $this->events->listen(RoomMessage::class, function (RoomMessage $event) use (&$heard): void {
            $heard[] = $event->body;
        });

        $this->events->dispatch(new RoomMessage('hello', 7));

        $this->assertSame(['hello'], $heard, 'broadcasting must not replace listening');
        $this->fake->assertBroadcast(RoomMessage::class);
    }

    /**
     * A broadcast that cannot be delivered must not take the application's own
     * listeners down with it — the row was saved either way.
     */
    public function test_a_failing_driver_does_not_stop_the_listeners(): void
    {
        $this->broadcast->extend('exploding', static fn (): Broadcaster => new class implements Broadcaster {
            public function broadcast(array $channels, string $event, array $payload = []): void
            {
                throw new \RuntimeException('the socket server is down');
            }
        });

        $this->broadcast->setDefaultDriver('exploding');

        $heard = false;

        $this->events->listen(RoomMessage::class, function () use (&$heard): void {
            $heard = true;
        });

        $this->events->dispatch(new RoomMessage('hello', 7));

        $this->assertTrue($heard);
    }

    // ─── What goes out ────────────────────────────────────

    public function test_the_payload_is_the_events_public_state(): void
    {
        $this->events->dispatch(new RoomMessage('hello', 7));

        $payload = $this->fake->broadcastsOf(RoomMessage::class)[0]['payload'];

        $this->assertSame('hello', $payload['body']);
        $this->assertSame(7, $payload['roomId']);
    }

    public function test_an_event_can_name_itself_and_its_payload(): void
    {
        $this->events->dispatch(new NamedEvent());

        $this->fake->assertBroadcast('room.renamed');

        $this->assertSame(
            ['only' => 'this'],
            $this->fake->broadcastsOf('room.renamed')[0]['payload'],
        );
    }

    public function test_the_three_channel_kinds_carry_their_prefixes(): void
    {
        $this->events->dispatch(new EveryChannel());

        $channels = $this->fake->broadcastsOf(EveryChannel::class)[0]['channels'];

        $this->assertSame(['open', 'private-secret', 'presence-lobby', 'private-encrypted-vault'], $channels);
    }

    public function test_an_event_with_no_channels_broadcasts_nothing(): void
    {
        $this->events->dispatch(new NoChannels());

        $this->fake->assertNothingBroadcast();
    }

    // ─── Not back to the sender ───────────────────────────

    /**
     * The sender's client already rendered it. Without excluding them they see
     * it twice, which only shows up with two browsers open.
     */
    public function test_an_event_can_exclude_the_connection_that_caused_it(): void
    {
        $event = new RoomMessage('hello', 7);
        $event->socket = 'socket-abc';

        $this->events->dispatch($event);

        $this->fake->assertBroadcastToOthers(RoomMessage::class);

        $this->assertSame(
            'socket-abc',
            $this->fake->broadcastsOf(RoomMessage::class)[0]['payload']['socket'],
        );
    }

    /**
     * The key is there and null, which is how a driver is told there is
     * nobody to exclude. Both real drivers strip it before the wire.
     */
    public function test_without_a_socket_it_goes_to_everyone(): void
    {
        $this->events->dispatch(new RoomMessage('hello', 7));

        $this->assertNull($this->fake->broadcastsOf(RoomMessage::class)[0]['payload']['socket']);
    }

    /** An event not using the trait carries no socket key at all. */
    public function test_an_event_without_the_trait_has_no_socket_key(): void
    {
        $this->events->dispatch(new UrgentMessage());

        $this->assertArrayNotHasKey(
            'socket',
            $this->fake->broadcastsOf(UrgentMessage::class)[0]['payload'],
        );
    }

    // ─── Choosing a connection ────────────────────────────

    public function test_an_event_can_name_the_connection_it_goes_out_on(): void
    {
        $other = new FakeBroadcaster();

        $this->broadcast->extend('other', static fn (): Broadcaster => $other);

        $event = new RoomMessage('hello', 7);
        $event->broadcastVia('other');

        $this->events->dispatch($event);

        $other->assertBroadcast(RoomMessage::class);
        $this->fake->assertNothingBroadcast();
    }

    public function test_an_event_can_go_out_on_several_connections(): void
    {
        $other = new FakeBroadcaster();

        $this->broadcast->extend('other', static fn (): Broadcaster => $other);

        $event = new RoomMessage('hello', 7);
        $event->broadcastVia(['fake', 'other']);

        $this->events->dispatch($event);

        $this->fake->assertBroadcast(RoomMessage::class);
        $other->assertBroadcast(RoomMessage::class);
    }

    // ─── Now versus queued ────────────────────────────────

    /** With no queue bound, a queued broadcast goes inline rather than vanishing. */
    public function test_a_queued_broadcast_falls_back_to_inline_with_no_queue(): void
    {
        $this->broadcast->queue(new RoomMessage('hello', 7));

        $this->fake->assertBroadcast(RoomMessage::class);
    }

    public function test_a_broadcast_now_event_never_queues(): void
    {
        $this->broadcast->queue(new UrgentMessage());

        $this->fake->assertBroadcast(UrgentMessage::class);
    }

    // ─── Channel authorisation ────────────────────────────

    public function test_a_channel_callback_decides_who_may_listen(): void
    {
        $this->broadcast->channel('rooms.{id}', static fn (object $user, string $id): bool => $id === '7');

        $user = new \stdClass();

        $this->assertTrue($this->broadcast->check($user, 'private-rooms.7'));
        $this->assertFalse($this->broadcast->check($user, 'private-rooms.8'));
    }

    /**
     * A presence callback returns the member's details, which is what the
     * other subscribers see. Flattening that to a boolean threw it away.
     */
    public function test_a_presence_callback_keeps_what_it_returned(): void
    {
        $this->broadcast->channel('lobby', static fn (object $user): array => ['name' => 'Ada']);

        $result = $this->broadcast->authorise(new \stdClass(), 'presence-lobby');

        $this->assertSame(['name' => 'Ada'], $result);
        $this->assertTrue($this->broadcast->check(new \stdClass(), 'presence-lobby'));
    }

    public function test_an_unregistered_channel_is_refused(): void
    {
        $this->assertFalse($this->broadcast->authorise(new \stdClass(), 'private-nobody-registered-this'));
        $this->assertFalse($this->broadcast->hasChannelFor('private-nobody-registered-this'));
    }

    public function test_a_registered_channel_is_recognised_with_its_prefix(): void
    {
        $this->broadcast->channel('rooms.{id}', static fn (): bool => true);

        $this->assertTrue($this->broadcast->hasChannelFor('private-rooms.7'));
        $this->assertTrue($this->broadcast->hasChannelFor('presence-rooms.7'));
        $this->assertTrue($this->broadcast->hasChannelFor('rooms.7'));
    }

    // ─── The fake's own assertions ────────────────────────

    public function test_the_fake_counts_and_filters(): void
    {
        $this->events->dispatch(new RoomMessage('one', 7));
        $this->events->dispatch(new RoomMessage('two', 7));

        $this->fake->assertBroadcastTimes(RoomMessage::class, 2);

        $this->fake->assertBroadcast(
            RoomMessage::class,
            null,
            static fn (array $payload): bool => $payload['body'] === 'two',
        );

        $this->fake->assertNotBroadcast(
            RoomMessage::class,
            static fn (array $payload): bool => $payload['body'] === 'three',
        );
    }

    public function test_a_missing_broadcast_fails_the_assertion(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->fake->assertBroadcast(RoomMessage::class);
    }
}

class RoomMessage implements ShouldBroadcast
{
    use InteractsWithSockets;
    use InteractsWithBroadcasting;

    public function __construct(
        public string $body = '',
        public int $roomId = 0,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('rooms.' . $this->roomId)];
    }
}

class UrgentMessage implements ShouldBroadcastNow
{
    public function broadcastOn(): array
    {
        return [new Channel('urgent')];
    }
}

class NamedEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [new Channel('rooms')];
    }

    public function broadcastAs(): string
    {
        return 'room.renamed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['only' => 'this'];
    }
}

class EveryChannel implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [
            new Channel('open'),
            new PrivateChannel('secret'),
            new PresenceChannel('lobby'),
            new EncryptedPrivateChannel('vault'),
        ];
    }
}

class NoChannels implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [];
    }
}

class NotBroadcastable
{
}
