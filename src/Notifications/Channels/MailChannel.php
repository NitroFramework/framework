<?php

namespace Nitro\Notifications\Channels;

use Nitro\Mail\Contracts\Mailer;
use Nitro\Notifications\Contracts\Channel;
use Nitro\Notifications\Notification;
use RuntimeException;

/** Delivers a notification's toMail() Message through the mailer. */
class MailChannel implements Channel
{
    public function __construct(protected Mailer $mailer) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMail')) {
            throw new RuntimeException(get_class($notification) . ' must define toMail() to use the mail channel.');
        }

        $message = $notification->toMail($notifiable);

        if ($message->to === [] && ($to = $notifiable->routeNotificationFor('mail'))) {
            $message->to((string) $to);
        }

        $this->mailer->send($message);
    }
}
