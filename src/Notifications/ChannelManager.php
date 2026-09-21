<?php

namespace Nitro\Notifications;

use InvalidArgumentException;
use Closure;
use Nitro\Mail\Contracts\Mailer;
use Nitro\Notifications\Channels\DatabaseChannel;
use Nitro\Notifications\Channels\MailChannel;
use Nitro\Notifications\Contracts\Channel;

/** Resolves notification channels by name. */
class ChannelManager
{
    /** @var array<string, Channel> */
    protected array $channels = [];

    /** @param Closure(): Mailer $mailer Built when the mail channel is first asked for. */
    public function __construct(private Closure $mailer) {}

    public function channel(string $name): Channel
    {
        return $this->channels[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): Channel
    {
        return match ($name) {
            'mail' => new MailChannel(($this->mailer)()),
            'database' => new DatabaseChannel(),
            default => throw new InvalidArgumentException("Notification channel [{$name}] is not supported."),
        };
    }
}
