<?php

namespace Nitro\Notifications;

use Nitro\Queue\Contracts\ShouldQueue;

/** Routes a notification to each of its channels for one or many notifiables. */
class NotificationSender
{
    public function __construct(protected ChannelManager $channels) {}

    /**
     * Send a notification, queueing it when it asks to be queued.
     *
     * Queued per notifiable rather than per call: sending to two hundred people
     * should be two hundred jobs, so one bad address fails one delivery instead
     * of abandoning the other hundred and ninety-nine mid-run.
     */
    public function send(object|iterable $notifiables, Notification $notification): void
    {
        foreach ($this->normalize($notifiables) as $notifiable) {
            if ($notification instanceof ShouldQueue) {
                SendQueuedNotification::dispatch($notifiable, $notification);

                continue;
            }

            $this->deliver($notifiable, $notification);
        }
    }

    /**
     * Send immediately, whatever the notification asks for.
     *
     * This is what the queued job calls when it runs, and what a caller uses to
     * bypass the queue deliberately. Going back through send() from the job
     * would see ShouldQueue again and re-queue it for ever.
     */
    public function sendNow(object|iterable $notifiables, Notification $notification): void
    {
        foreach ($this->normalize($notifiables) as $notifiable) {
            $this->deliver($notifiable, $notification);
        }
    }

    protected function deliver(object $notifiable, Notification $notification): void
    {
        foreach ($notification->via($notifiable) as $channel) {
            $this->channels->channel($channel)->send($notifiable, $notification);
        }
    }

    /** @return iterable<int, object> */
    protected function normalize(object|iterable $notifiables): iterable
    {
        return is_object($notifiables) ? [$notifiables] : $notifiables;
    }
}
