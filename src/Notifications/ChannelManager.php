<?php

namespace Nitro\Notifications;

use InvalidArgumentException;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Notifications\Channels\DatabaseChannel;
use Nitro\Notifications\Channels\MailChannel;
use Nitro\Notifications\Contracts\Channel;

/** Resolves notification channels by name. */
class ChannelManager
{
    /** @var array<string, Channel> */
    protected array $channels = [];

    public function __construct(protected ContainerInterface $container) {}

    public function channel(string $name): Channel
    {
        return $this->channels[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): Channel
    {
        return match ($name) {
            'mail' => new MailChannel($this->container->make('mailer')),
            'database' => new DatabaseChannel(),
            default => throw new InvalidArgumentException("Notification channel [{$name}] is not supported."),
        };
    }
}
