<?php

namespace Nitro\Notifications\Channels;

use Nitro\Broadcasting\Channel as BroadcastingChannel;
use Nitro\Notifications\Contracts\Channel;
use Nitro\Notifications\Events\BroadcastNotificationCreated;
use Nitro\Notifications\Messages\BroadcastMessage;
use Nitro\Notifications\Notification;
use RuntimeException;

/**
 * Pushes a notification to the notifiable's broadcast channels.
 *
 * What puts a bell badge on a page without the page polling for it.
 */
class BroadcastChannel implements Channel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $this->messageFor($notifiable, $notification);

        $channels = $this->channelsFor($notifiable, $notification);

        if ($channels === []) {
            return;
        }

        \app('broadcast')->send(
            $channels,
            $message->event ?? $this->eventNameFor($notification),
            $this->payloadFor($notification, $message),
        );
    }

    /** What the notification says to broadcast. */
    protected function messageFor(object $notifiable, Notification $notification): BroadcastMessage
    {
        if (! method_exists($notification, 'toBroadcast')) {
            // toArray is the shared payload a notification usually already
            // has for the database channel.
            if (method_exists($notification, 'toArray')) {
                return new BroadcastMessage($notification->toArray($notifiable));
            }

            throw new RuntimeException(
                $notification::class . ' must define toBroadcast() or toArray() to use the broadcast channel.'
            );
        }

        $message = $notification->toBroadcast($notifiable);

        return $message instanceof BroadcastMessage ? $message : new BroadcastMessage((array) $message);
    }

    /**
     * Where it goes.
     *
     * The notifiable's own channel unless the notification names one,
     * so a notification reaches the person it is about by default.
     *
     * @return array<int, string>
     */
    protected function channelsFor(object $notifiable, Notification $notification): array
    {
        $declared = $notification->broadcastOn();

        if ($declared !== [] && $declared !== '') {
            return $this->names($declared);
        }

        if (method_exists($notifiable, 'receivesBroadcastNotificationsOn')) {
            return $this->names($notifiable->receivesBroadcastNotificationsOn($notification));
        }

        $key = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : ($notifiable->id ?? null);

        if ($key === null) {
            return [];
        }

        return [str_replace('\\', '.', $notifiable::class) . '.' . $key];
    }

    /** @return array<int, string> */
    private function names(mixed $channels): array
    {
        return array_values(array_map(
            static fn (mixed $channel): string => $channel instanceof BroadcastingChannel
                ? $channel->name
                : (string) $channel,
            is_array($channels) ? $channels : [$channels],
        ));
    }

    private function eventNameFor(Notification $notification): string
    {
        return BroadcastNotificationCreated::class;
    }

    /**
     * The payload, carrying the notification's identity alongside its data.
     *
     * A client needs the id to mark the same notification read, and the
     * type to decide how to render it.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(Notification $notification, BroadcastMessage $message): array
    {
        return array_merge($message->data, [
            'id' => $notification->id,
            'type' => $notification::class,
        ]);
    }
}
