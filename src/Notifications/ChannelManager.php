<?php

namespace Nitro\Notifications;

use Closure;
use InvalidArgumentException;
use Nitro\Notifications\Channels\BroadcastChannel;
use Nitro\Notifications\Channels\DatabaseChannel;
use Nitro\Notifications\Channels\MailChannel;
use Nitro\Notifications\Contracts\Channel;

/**
 * Resolves notification channels by name, and sends through them.
 *
 * The entry point the Notification facade proxies to, so sending and
 * the channels it sends on are reached the same way.
 */
class ChannelManager
{
    /** @var array<string, Channel> */
    protected array $channels = [];

    /** @var array<string, Closure(): Channel> Channels an application added. */
    protected array $customChannels = [];

    /** The channel used when a notification names none. */
    protected string $defaultChannel = 'mail';

    private NotificationSender $sender;

    public function __construct(
        private Closure $mailer,
        ?\Nitro\Events\Contracts\Dispatcher $events = null,
    ) {
        $this->sender = new NotificationSender($this, $events);
    }

    public function channel(?string $name = null): Channel
    {
        $name ??= $this->getDefaultDriver();

        return $this->channels[$name] ??= $this->resolve($name);
    }

    /** The same, under the name a manager usually gives it. */
    public function driver(?string $name = null): Channel
    {
        return $this->channel($name);
    }

    protected function resolve(string $name): Channel
    {
        if (isset($this->customChannels[$name])) {
            return ($this->customChannels[$name])();
        }

        return match ($name) {
            'mail' => new MailChannel(($this->mailer)()),
            'database' => new DatabaseChannel(),
            'broadcast' => new BroadcastChannel(),
            default => throw new InvalidArgumentException("Notification channel [{$name}] is not supported."),
        };
    }

    /**
     * Add a channel of your own.
     *
     * @param Closure(): Channel $resolver
     */
    public function extend(string $name, Closure $resolver): static
    {
        $this->customChannels[$name] = $resolver;

        unset($this->channels[$name]);

        return $this;
    }

    /** Replace a channel with a given instance, for a test. */
    public function set(string $name, Channel $channel): static
    {
        $this->channels[$name] = $channel;

        return $this;
    }

    public function getDefaultDriver(): string
    {
        return $this->defaultChannel;
    }

    /** The channel used when a notification names none. */
    public function deliversVia(): string
    {
        return $this->getDefaultDriver();
    }

    public function deliverVia(string $channel): static
    {
        $this->defaultChannel = $channel;

        return $this;
    }

    // ── Sending ───────────────────────────────────────────────────────

    /** @param array<int, string>|null $channels */
    public function send(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        $this->sender->send($notifiables, $notification, $channels);
    }

    /** @param array<int, string>|null $channels */
    public function sendNow(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        $this->sender->sendNow($notifiables, $notification, $channels);
    }

    /** Begin a notification to an address rather than a record. */
    public function route(string $channel, mixed $route): AnonymousNotifiable
    {
        return (new AnonymousNotifiable())->route($channel, $route);
    }

    /**
     * What does the sending, built against this manager.
     *
     * Built in the constructor rather than injected, because the sender
     * needs the manager to reach a channel and the two would otherwise
     * have to resolve each other.
     */
    public function sender(): NotificationSender
    {
        return $this->sender;
    }
}
