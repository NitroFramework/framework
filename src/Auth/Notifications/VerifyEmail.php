<?php

namespace Nitro\Auth\Notifications;

use Nitro\Notifications\Messages\MailMessage;
use Nitro\Notifications\Notification;

/**
 * The link that confirms a user owns the address they gave.
 */
class VerifyEmail extends Notification
{
    /** Builds the message, when an application would rather write its own. */
    public static ?\Closure $toMailCallback = null;

    /** Builds the URL, for an application whose verify page is elsewhere. */
    public static ?\Closure $createUrlCallback = null;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        if (static::$toMailCallback !== null) {
            return (static::$toMailCallback)($notifiable, $url);
        }

        return (new MailMessage())
            ->subject('Verify your email address')
            ->line('Please confirm your email address by pressing the button below.')
            ->action('Verify email address', $url)
            ->line('If you did not create an account, no further action is required.');
    }

    /**
     * The link, signed so it cannot be forged or replayed past its time.
     */
    protected function verificationUrl(object $notifiable): string
    {
        if (static::$createUrlCallback !== null) {
            return (static::$createUrlCallback)($notifiable);
        }

        $id = method_exists($notifiable, 'getKey')
            ? $notifiable->getKey()
            : ($notifiable->id ?? '');

        $email = method_exists($notifiable, 'getEmailForVerification')
            ? $notifiable->getEmailForVerification()
            : (string) ($notifiable->email ?? '');

        $parameters = ['id' => (string) $id, 'hash' => sha1($email)];

        try {
            return \app('url')->temporarySignedRoute('verification.verify', 3600, $parameters);
        } catch (\Throwable) {
            return '/verify-email/' . $parameters['id'] . '/' . $parameters['hash'];
        }
    }

    public static function toMailUsing(?\Closure $callback): void
    {
        static::$toMailCallback = $callback;
    }

    public static function createUrlUsing(?\Closure $callback): void
    {
        static::$createUrlCallback = $callback;
    }
}
