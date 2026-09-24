<?php

namespace Tests\Unit\Broadcasting;

use Nitro\Broadcasting\BroadcastManager;
use Nitro\Broadcasting\Channel;
use Nitro\Broadcasting\Contracts\Broadcaster;
use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Broadcasting\Drivers\NullBroadcaster;
use Nitro\Broadcasting\PresenceChannel;
use Nitro\Broadcasting\PrivateChannel;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Config;
use PHPUnit\Framework\TestCase;

/**
 * Sending events to listening clients, and deciding who may listen.
 */
class BroadcastTest extends TestCase
{
    private BroadcastManager $broadcast;
    private SpyBroadcaster $spy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spy = new SpyBroadcaster();
        $this->broadcast = new BroadcastManager(
            new NewingClassResolver(),
            Config::fromArray(['broadcasting' => ['default' => 'null']]),
        );

        $this->broadcast->extend('spy', fn (): SpyBroadcaster => $this->spy);
    }

    // ─── Channels ─────────────────────────────────────────

    public function test_channel_names_carry_their_prefix(): void
    {
        $this->assertSame('orders', (new Channel('orders'))->name);
        $this->assertSame('private-orders', (new PrivateChannel('orders'))->name);
        $this->assertSame('presence-room', (new PresenceChannel('room'))->name);
    }

    public function test_a_channel_reads_as_its_name(): void
    {
        $this->assertSame('orders', (string) new Channel('orders'));
    }

    // ─── Sending ──────────────────────────────────────────

    public function test_an_event_reaches_the_driver(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->broadcast->event(new OrderShipped());

        $this->assertCount(1, $this->spy->sent);
        $this->assertSame(['private-orders.1'], $this->spy->sent[0]['channels']);
        $this->assertSame('order.shipped', $this->spy->sent[0]['event']);
        $this->assertSame(['id' => 1], $this->spy->sent[0]['payload']);
    }

    public function test_an_event_without_overrides_uses_its_class_and_properties(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->broadcast->event(new PlainEvent());

        $this->assertSame(PlainEvent::class, $this->spy->sent[0]['event']);
        $this->assertSame(['reference' => 'ABC'], $this->spy->sent[0]['payload']);
    }

    public function test_a_payload_can_be_given_at_the_call(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->broadcast->event(new OrderShipped(), ['replaced' => true]);

        $this->assertSame(['replaced' => true], $this->spy->sent[0]['payload']);
    }

    public function test_an_event_on_no_channels_is_not_sent(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->broadcast->event(new SilentEvent());

        $this->assertSame([], $this->spy->sent);
    }

    public function test_a_message_can_be_sent_without_an_event_object(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->broadcast->send('orders', 'ping', ['at' => 1]);

        $this->assertSame(['orders'], $this->spy->sent[0]['channels']);
        $this->assertSame('ping', $this->spy->sent[0]['event']);
    }

    // ─── Authorisation ────────────────────────────────────

    public function test_a_placeholder_is_passed_to_the_authoriser(): void
    {
        $this->broadcast->channel('orders.{id}', fn (mixed $user, string $id): bool => $id === '7');

        $this->assertTrue($this->broadcast->check(null, 'private-orders.7'));
        $this->assertFalse($this->broadcast->check(null, 'private-orders.8'));
    }

    public function test_the_prefix_is_stripped_before_matching(): void
    {
        $this->broadcast->channel('room.{name}', fn (): bool => true);

        $this->assertTrue($this->broadcast->check(null, 'presence-room.lobby'));
        $this->assertTrue($this->broadcast->check(null, 'room.lobby'));
    }

    /** Forgetting to authorise a channel closes it rather than opening it. */
    public function test_an_unclaimed_channel_is_refused(): void
    {
        $this->assertFalse($this->broadcast->check(null, 'private-anything'));
    }

    public function test_the_user_reaches_the_authoriser(): void
    {
        $this->broadcast->channel('private.{id}', fn (mixed $user): bool => $user === 'ada');

        $this->assertTrue($this->broadcast->check('ada', 'private-private.1'));
        $this->assertFalse($this->broadcast->check('bob', 'private-private.1'));
    }

    public function test_registered_channels_are_readable(): void
    {
        $this->broadcast->channel('orders.{id}', fn (): bool => true);

        $this->assertArrayHasKey('orders.{id}', $this->broadcast->getChannels());
    }

    // ─── Drivers ──────────────────────────────────────────

    public function test_the_default_driver_sends_nowhere(): void
    {
        $this->assertInstanceOf(NullBroadcaster::class, $this->broadcast->connection());
    }

    public function test_a_driver_is_built_once(): void
    {
        $this->broadcast->setDefaultDriver('spy');

        $this->assertSame($this->broadcast->connection(), $this->broadcast->connection());
    }

    public function test_driver_is_an_alias_of_connection(): void
    {
        $this->assertSame($this->broadcast->connection(), $this->broadcast->driver());
    }

    public function test_the_default_driver_can_be_read_and_changed(): void
    {
        $this->assertSame('null', $this->broadcast->getDefaultDriver());

        $this->broadcast->setDefaultDriver('spy');

        $this->assertSame('spy', $this->broadcast->getDefaultDriver());
    }

    /** 'pusher' was the name used here until it became a real driver. */
    public function test_an_unknown_driver_raises(): void
    {
        $this->broadcast->setDefaultDriver('carrier-pigeon');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $this->broadcast->connection();
    }

    /**
     * A driver that ships but has not been configured says which value it
     * wants, rather than "not registered" — which would send someone looking
     * for a driver that is right there.
     */
    public function test_a_shipped_driver_with_no_credentials_says_what_is_missing(): void
    {
        $this->broadcast->setDefaultDriver('pusher');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[key]');

        $this->broadcast->connection();
    }

    public function test_a_channel_authoriser_may_be_named_by_class(): void
    {
        $this->broadcast->channel('orders.{id}', OrderChannel::class);

        $this->assertTrue($this->broadcast->check('ada', 'orders.7'));
        $this->assertFalse($this->broadcast->check('ada', 'orders.8'));
    }
}

/** Instantiates a class with no dependencies of its own. */
final class NewingClassResolver implements ClassResolver
{
    public function resolve(string $class): object
    {
        return new $class();
    }
}

/** A channel authoriser registered by class string rather than closure. */
class OrderChannel
{
    public function join(mixed $user, string $id): bool
    {
        return $id === '7';
    }
}

class SpyBroadcaster implements Broadcaster
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->sent[] = compact('channels', 'event', 'payload');
    }
}

class OrderShipped implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.1')];
    }

    public function broadcastAs(): string
    {
        return 'order.shipped';
    }

    public function broadcastWith(): array
    {
        return ['id' => 1];
    }
}

class PlainEvent implements ShouldBroadcast
{
    public string $reference = 'ABC';

    public function broadcastOn(): Channel
    {
        return new Channel('plain');
    }
}

class SilentEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [];
    }
}
