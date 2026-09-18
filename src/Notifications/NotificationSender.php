<?php

namespace Nitro\Notifications;

use Nitro\Notifications\Events\NotificationFailed;
use Nitro\Notifications\Events\NotificationSending;
use Nitro\Notifications\Events\NotificationSent;
use Nitro\Queue\Contracts\ShouldQueue;
use Throwable;

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

    /**
     * Begin notifying somebody by address rather than by model.
     *
     *   Notification::route('mail', 'ops@example.com')->notify(new Alert());
     */
    public function route(string $channel, mixed $route): AnonymousNotifiable
    {
        return (new AnonymousNotifiable())->route($channel, $route);
    }

    /**
     * Deliver on every channel the notification asks for.
     *
     * Each channel is independent: one failing is reported and the rest still
     * go out, so a bad email address does not also cost the database record.
     * The exception is not re-thrown, because a notification is a side effect
     * of whatever the caller was actually doing.
     */
    protected function deliver(object $notifiable, Notification $notification): void
    {
        foreach ($notification->via($notifiable) as $channel) {
            $this->dispatch(new NotificationSending($notifiable, $notification, $channel));

            try {
                $this->channels->channel($channel)->send($notifiable, $notification);
            } catch (Throwable $exception) {
                $this->dispatch(new NotificationFailed($notifiable, $notification, $channel, $exception));

                continue;
            }

            $this->dispatch(new NotificationSent($notifiable, $notification, $channel));
        }
    }

    /**
     * Fire an event, when there is a dispatcher to fire it on.
     */
    protected function dispatch(object $event): void
    {
        $container = app();

        if ($container->has('events')) {
            $container->createOrResolve('events')->dispatch($event);
        }
    }

    /** @return iterable<int, object> */
    protected function normalize(object|iterable $notifiables): iterable
    {
        return is_object($notifiables) ? [$notifiables] : $notifiables;
    }
}
