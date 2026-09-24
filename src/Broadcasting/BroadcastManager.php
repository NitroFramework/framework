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

        $names = array_map(
            static fn (Channel|string $channel): string => $channel instanceof Channel
                ? $channel->name
                : (string) $channel,
            $channels,
        );

        $name = $this->nameFor($event);
        $data = $payload ?? $this->payloadFor($event);

        // The connection that caused the event, when the event asked to be
        // kept from it. Carried in the payload because that is what a driver
        // receives, and a driver that cannot exclude simply ignores it.
        if (($socket = $this->socketFor($event)) !== null) {
            $data['socket'] = $socket;
        }

        foreach ($this->connectionsFor($event) as $connection) {
            $connection->broadcast($names, $name, $data);
        }
    }

    /**
     * Send an event the way it asked to be sent.
     *
     * A plain {@see ShouldBroadcast} is queued, so the request does not wait
     * on a third party's network call. A {@see ShouldBroadcastNow} goes out
     * inline. With no queue bound, a queued broadcast is sent inline rather
     * than dropped — silence would be the harder failure to find.
     */
    public function queue(ShouldBroadcast $event): void
    {
        if ($event instanceof ShouldBroadcastNow) {
            $this->event($event);

            return;
        }

        $queue = $this->queueFor($event);

        if ($queue === null) {
            $this->event($event);

            return;
        }

        $queue->dispatch(new BroadcastEvent($event));
    }

    /**
     * The queue a broadcast should go on, or null when there is none.
     *
     * Resolved rather than injected, because most applications broadcast
     * inline and asking for a queue manager at construction would make every
     * one of them build the queue layer.
     */
    protected function queueFor(ShouldBroadcast $event): ?\Nitro\Queue\QueueManager
    {
        if (! \Nitro\Container\Container::hasInstance()) {
            return null;
        }

        $container = \Nitro\Container\Container::getInstance();

        if (! $container->has(\Nitro\Queue\QueueManager::class)) {
            return null;
        }

        return $container->resolve(\Nitro\Queue\QueueManager::class);
    }

    /**
     * The drivers this event goes out on.
     *
     * An event naming its own connections through
     * {@see InteractsWithBroadcasting} overrides the default, so one noisy
     * event can take a different route from everything else.
     *
     * @return array<int, Broadcaster>
     */
    protected function connectionsFor(ShouldBroadcast $event): array
    {
        $named = method_exists($event, 'broadcastConnections')
            ? $event->broadcastConnections()
            : [];

        if ($named === []) {
            return [$this->connection()];
        }

        return array_map(fn (string $name): Broadcaster => $this->connection($name), $named);
    }

    /** The connection to keep this broadcast away from, if any. */
    protected function socketFor(ShouldBroadcast $event): ?string
    {
        return property_exists($event, 'socket') && is_string($event->socket) && $event->socket !== ''
            ? $event->socket
            : null;
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
     * Serve the endpoint a client asks for permission at.
     *
     *     Broadcast::routes();
     *     Broadcast::channel('orders.{id}', fn ($user, $id) => $user->owns($id));
     *
     * Called by the application, beside the channels it registers, rather than
     * by the provider at boot: a provider that registers a route has to boot
     * on every request, and most applications broadcast nothing.
     *
     * Behind the 'web' group by default, so the session is loaded and CSRF
     * verified: the question being answered is "may this signed-in person
     * listen here", and without the session there is nobody to ask about. The
     * path is configurable because an application already serving something
     * at /broadcasting has to be able to move it.
     *
     * @param array<string, mixed>|null $attributes Route group attributes, in
     *                                              place of the 'web' group.
     */
    public function routes(?array $attributes = null): void
    {
        $container = \Nitro\Container\Container::getInstance();

        if (! $container->has(\Nitro\Routing\Router::class)) {
            return;
        }

        $app = $container->has(\Nitro\Foundation\Contracts\ApplicationInterface::class)
            ? $container->resolve(\Nitro\Foundation\Contracts\ApplicationInterface::class)
            : null;

        // A cached route table already holds this route, and adding it again
        // would register it twice.
        if ($app?->routesAreCached()) {
            return;
        }

        $path = (string) $this->config->get('broadcasting.auth_path', '/broadcasting/auth');

        $router = $container->resolve(\Nitro\Routing\Router::class);

        $router->group($attributes ?? ['middleware' => ['web']], static function () use ($router, $path): void {
            // The [class, method] form rather than the bare class string: the
            // router calls class_exists() on a string action to work out
            // whether it is a single-action class, and that would load the
            // controller while routes are registered.
            $router->post($path, [BroadcastController::class, '__invoke'])->name('broadcasting.auth');
        });
    }

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
        return $this->authorise($user, $channel) !== false;
    }

    /**
     * Ask the channel's callback, keeping what it said.
     *
     * {@see check()} flattens the answer to a boolean, which is enough for a
     * private channel and loses what a presence channel needs: returning an
     * array from the callback is how the member describes itself to the other
     * subscribers, and casting that to true threw it away.
     *
     * @return array<string, mixed>|bool False when refused.
     */
    public function authorise(mixed $user, string $channel): array|bool
    {
        $callback = $this->channelFor($channel, $parameters);

        if ($callback === null) {
            return false;
        }

        $result = $callback($user, ...$parameters);

        return is_array($result) ? $result : (bool) $result;
    }

    /** Whether any registered pattern covers this channel. */
    public function hasChannelFor(string $channel): bool
    {
        return $this->channelFor($channel, $parameters) !== null;
    }

    /**
     * The callback for a channel, and the placeholders it matched.
     *
     * @param array<int, string>|null $parameters Filled with the matches.
     */
    protected function channelFor(string $channel, ?array &$parameters): ?callable
    {
        // The prefix is the socket server's convention, not part of the name
        // the application registered.
        $name = preg_replace('/^(private-|presence-)/', '', $channel) ?? $channel;

        foreach ($this->channels as $pattern => $callback) {
            $matched = $this->matchPattern($pattern, $name);

            if ($matched === null) {
                continue;
            }

            $parameters = $matched;

            return is_string($callback)
                ? [$this->resolver->resolve($callback), 'join']
                : $callback;
        }

        $parameters = [];

        return null;
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

        $config = (array) $this->config->get("broadcasting.connections.{$name}", []);

        return match ($config['driver'] ?? $name) {
            'log' => new LogBroadcaster(),
            'null' => new NullBroadcaster(),
            'redis' => $this->createRedisDriver($config),
            'pusher' => new Drivers\PusherBroadcaster($config),
            default => throw new RuntimeException(
                "Broadcast driver [{$name}] is not registered. Add it with Broadcast::extend()."
            ),
        };
    }

    /**
     * The Redis driver, which needs the Redis layer to be there.
     *
     * Asked for through the container rather than the constructor, so an
     * application broadcasting over Pusher never builds a Redis manager.
     *
     * @param array<string, mixed> $config
     */
    protected function createRedisDriver(array $config): Broadcaster
    {
        if (! \Nitro\Container\Container::hasInstance()) {
            throw new RuntimeException(
                'The redis broadcast driver needs the application container to reach the Redis layer.'
            );
        }

        $container = \Nitro\Container\Container::getInstance();
        $name = isset($config['connection']) ? (string) $config['connection'] : null;

        return new Drivers\RedisBroadcaster(
            // Resolved on each publish rather than now, so a worker that has
            // been idle does not hold a connection the server has dropped.
            static fn (): object => $container
                ->resolve(\Nitro\Redis\RedisManager::class)
                ->connection($name),
            (string) ($config['prefix'] ?? ''),
        );
    }
}
