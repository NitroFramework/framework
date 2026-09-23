<?php

namespace Nitro\Notifications;

use Nitro\Events\Contracts\Dispatcher;
use Nitro\Notifications\Events\NotificationFailed;
use Nitro\Notifications\Events\NotificationSending;
use Nitro\Notifications\Events\NotificationSent;
use Nitro\Notifications\Events\NotificationSkipped;
use Nitro\Queue\Contracts\ShouldQueue;
use Throwable;

/**
 * Delivers a notification to each of its notifiables, on each channel.
 */
class NotificationSender
{
    public function __construct(
        protected ChannelManager $channels,
        protected ?Dispatcher $events = null,
    ) {}

    /**
     * Send a notification, queueing it when it asks to be queued.
     *
     * @param array<int, string>|null $channels Override what via() names.
     */
    public function send(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        if ($notification instanceof ShouldQueue) {
            $this->queue($notifiables, $notification, $channels);

            return;
        }

        $this->sendNow($notifiables, $notification, $channels);
    }

    /**
     * Send it in this process, whatever it asked for.
     *
     * @param array<int, string>|null $channels Override what via() names.
     */
    public function sendNow(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        foreach ($this->normalize($notifiables) as $notifiable) {
            $via = $channels ?: $notification->via($notifiable);

            if ($via === []) {
                continue;
            }

            // One identifier per notifiable, shared by every channel it goes
            // out on, so the copy in a database and the one in an inbox are
            // recognisably the same notification.
            $id = bin2hex(random_bytes(16));

            foreach ($via as $channel) {
                // An anonymous notifiable is an address, not a record, so
                // there is nothing for a database row to belong to.
                if ($notifiable instanceof AnonymousNotifiable && $channel === 'database') {
                    continue;
                }

                $this->sendToNotifiable($notifiable, $id, clone $notification, $channel);
            }
        }
    }

    /**
     * Put it on the queue rather than sending it now.
     *
     * @param array<int, string>|null $channels
     */
    public function queue(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        foreach ($this->normalize($notifiables) as $notifiable) {
            $queued = new SendQueuedNotification($notifiable, clone $notification, $channels);

            (new \Nitro\Queue\PendingDispatch($queued))
                ->onConnection($notification->connection)
                ->onQueue($notification->queue)
                ->delay($notification->delay);
        }
    }

    /** Begin a notification to an address rather than a record. */
    public function route(string $channel, mixed $route): AnonymousNotifiable
    {
        return (new AnonymousNotifiable())->route($channel, $route);
    }

    /**
     * Deliver one notification on one channel.
     *
     * @throws Throwable Whatever the channel threw, after it is reported.
     */
    protected function sendToNotifiable(object $notifiable, string $id, Notification $notification, string $channel): void
    {
        $notification->id ??= $id;

        if (! $this->shouldSend($notifiable, $notification, $channel)) {
            $this->dispatch(new NotificationSkipped($notifiable, $notification, $channel));

            return;
        }

        try {
            $response = $this->channels->channel($channel)->send($notifiable, $notification);
        } catch (Throwable $exception) {
            $this->dispatch(new NotificationFailed($notifiable, $notification, $channel, $exception));

            throw $exception;
        }

        if (method_exists($notification, 'afterSending')) {
            $notification->afterSending($notifiable, $channel, $response);
        }

        $this->dispatch(new NotificationSent($notifiable, $notification, $channel, $response));
    }

    /**
     * Whether this notification should go out on this channel.
     *
     * The notification decides first through shouldSend(); after that a
     * listener returning false from NotificationSending cancels it,
     * which is how sending is suppressed outside working hours or on a
     * staging environment without editing each notification.
     */
    protected function shouldSend(object $notifiable, Notification $notification, string $channel): bool
    {
        if (method_exists($notification, 'shouldSend')
            && $notification->shouldSend($notifiable, $channel) === false) {
            return false;
        }

        if ($this->events === null) {
            return true;
        }

        return $this->events->until(new NotificationSending($notifiable, $notification, $channel)) !== false;
    }

    protected function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /** @return iterable<int, object> */
    protected function normalize(object|iterable $notifiables): iterable
    {
        return is_object($notifiables) ? [$notifiables] : $notifiables;
    }
}
