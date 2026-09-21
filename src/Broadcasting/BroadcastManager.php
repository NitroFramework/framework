<?php

namespace Nitro\Broadcasting;

use Closure;
use Nitro\Broadcasting\Contracts\Broadcaster;
use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Broadcasting\Drivers\LogBroadcaster;
use Nitro\Broadcasting\Drivers\NullBroadcaster;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;
use RuntimeException;

/**
 * Sends events to listening clients.
 *
 *     Broadcast::event(new OrderShipped($order));
 *     Broadcast::channel('orders.{id}', fn ($user, $id) => $user->owns($id));
 *
 * A driver decides where the message actually goes. The framework ships the
 * two that need nothing installed — log and null — and an application adds
 * its own with extend().
 */
class BroadcastManager
{
    /** @var array<string, Broadcaster> Resolved drivers, by name. */
    protected array $drivers = [];

    /** @var array<string, Closure|string> Custom driver factories. */
    protected array $customCreators = [];

    /** @var array<string, callable|string> Channel authorisers, by pattern. */
    protected array $channels = [];

    /** Set by setDefaultDriver(), otherwise read from config on first use. */
    protected ?string $default = null;

    public function __construct(
        protected ClassResolver $resolver,
        protected ConfigRepository $config,
    ) {}

    /** The driver a name resolves to, building it the first time. */
    public function connection(?string $name = null): Broadcaster
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /** Alias of {@see connection()}. */
    public function driver(?string $name = null): Broadcaster
    {
        return $this->connection($name);
    }

    /**
     * Register a driver the framework does not ship.
     *
     * @param Closure $factory Receives a {@see ClassResolver} and returns a
     *                         {@see Broadcaster}.
     */
    public function extend(string $name, Closure $factory): static
    {
        $this->customCreators[$name] = $factory;

        unset($this->drivers[$name]);

        return $this;
    }

    public function setDefaultDriver(string $name): static
    {
        $this->default = $name;

        return $this;
    }

    /** The connection broadcasts go out on when none is named. */
    public function getDefaultDriver(): string
    {
        return $this->default ??= (string) $this->config->get('broadcasting.default', 'null');
    }

    /**
     * Send an event out on the channels it names.
     *
     * @param array<string, mixed>|null $payload Overrides the event's own data.
     */
    public function event(ShouldBroadcast $event, ?array $payload = null): void
    {
        $channels = $event->broadcastOn();
        $channels = is_array($channels) ? $channels : [$channels];

        if ($channels === []) {
            return;
        }

        $this->connection()->broadcast(
            array_map(static fn (Channel $channel): string => $channel->name, $channels),
            $this->nameFor($event),
            $payload ?? $this->payloadFor($event)
        );
    }

    /**
     * Send a message to named channels without an event object.
     *
     * @param array<int, string>|string $channels
     * @param array<string, mixed>      $payload
     */
    public function send(array|string $channels, string $event, array $payload = []): void
    {
        $this->connection()->broadcast((array) $channels, $event, $payload);
    }

    // ─── Channel authorisation ────────────────────────────

    /**
     * Say who may listen on a channel.
     *
     * The pattern may carry {placeholders}, which are passed to the callback
     * after the user: 'orders.{id}' calls back with ($user, $id).
     */
    public function channel(string $pattern, callable|string $callback): static
    {
        $this->channels[$pattern] = $callback;

        return $this;
    }

    /**
     * Whether this user may listen on this channel.
     *
     * A channel nobody claimed is refused, so forgetting to authorise one
     * closes it rather than opening it.
     */
    public function check(mixed $user, string $channel): bool
    {
        $name = preg_replace('/^(private-|presence-)/', '', $channel) ?? $channel;

        foreach ($this->channels as $pattern => $callback) {
            $parameters = $this->matchPattern($pattern, $name);

            if ($parameters === null) {
                continue;
            }

            if (is_string($callback)) {
                $callback = [$this->resolver->resolve($callback), 'join'];
            }

            return (bool) $callback($user, ...$parameters);
        }

        return false;
    }

    /** @return array<string, callable|string> */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Match a channel against a pattern, returning its placeholder values.
     *
     * @return array<int, string>|null Null when the pattern does not match.
     */
    protected function matchPattern(string $pattern, string $channel): ?array
    {
        $regex = '#^' . preg_replace('/\\\{[^}]+\\\}/', '([^.]+)', preg_quote($pattern, '#')) . '$#';

        if (preg_match($regex, $channel, $matches) !== 1) {
            return null;
        }

        array_shift($matches);

        return $matches;
    }

    /** The name clients listen for. */
    protected function nameFor(ShouldBroadcast $event): string
    {
        return method_exists($event, 'broadcastAs') ? $event->broadcastAs() : $event::class;
    }

    /**
     * The data sent with the event.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(ShouldBroadcast $event): array
    {
        if (method_exists($event, 'broadcastWith')) {
            return $event->broadcastWith();
        }

        return get_object_vars($event);
    }

    /** @throws RuntimeException When the driver is unknown. */
    protected function resolve(string $name): Broadcaster
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->resolver);
        }

        return match ($name) {
            'log' => new LogBroadcaster(),
            'null' => new NullBroadcaster(),
            default => throw new RuntimeException(
                "Broadcast driver [{$name}] is not registered. Add it with Broadcast::extend()."
            ),
        };
    }
}
