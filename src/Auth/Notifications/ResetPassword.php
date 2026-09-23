<?php

namespace Nitro\Auth\Notifications;

use Nitro\Notifications\Messages\MailMessage;
use Nitro\Notifications\Notification;

/**
 * The link that lets a user set a new password.
 */
class ResetPassword extends Notification
{
    /** Builds the message, when an application would rather write its own. */
    public static ?\Closure $toMailCallback = null;

    /** Builds the URL, for an application whose reset page is elsewhere. */
    public static ?\Closure $createUrlCallback = null;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (static::$toMailCallback !== null) {
            return (static::$toMailCallback)($notifiable, $this->token);
        }

        $minutes = (int) ceil($this->expiresIn() / 60);

        return (new MailMessage())
            ->subject('Reset your password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line("This link will expire in {$minutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }

    protected function resetUrl(object $notifiable): string
    {
        if (static::$createUrlCallback !== null) {
            return (static::$createUrlCallback)($notifiable, $this->token);
        }

        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) ($notifiable->email ?? '');

        return $this->baseUrl() . '/reset-password/' . $this->token . '?email=' . urlencode($email);
    }

    protected function expiresIn(): int
    {
        try {
            return (int) \app('config')->get('auth.passwords.expire', 3600);
        } catch (\Throwable) {
            return 3600;
        }
    }

    protected function baseUrl(): string
    {
        try {
            return rtrim((string) \app('config')->get('app.url', ''), '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Build the message some other way. */
    public static function toMailUsing(?\Closure $callback): void
    {
        static::$toMailCallback = $callback;
    }

    /** Point the link somewhere else. */
    public static function createUrlUsing(?\Closure $callback): void
    {
        static::$createUrlCallback = $callback;
    }
}
