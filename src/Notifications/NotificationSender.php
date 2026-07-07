<?php

namespace Nitro\Notifications;

/** Routes a notification to each of its channels for one or many notifiables. */
class NotificationSender
{
    public function __construct(protected ChannelManager $channels) {}

    public function send(object|iterable $notifiables, Notification $notification): void
    {
        foreach ($this->normalize($notifiables) as $notifiable) {
            foreach ($notification->via($notifiable) as $channel) {
                $this->channels->channel($channel)->send($notifiable, $notification);
            }
        }
    }

    /** @return iterable<int, object> */
    protected function normalize(object|iterable $notifiables): iterable
    {
        return is_object($notifiables) ? [$notifiables] : $notifiables;
    }
}
