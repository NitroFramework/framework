<?php

namespace Nitro\Facades;

/**
 * Mail facade — send mail through the configured mailer.
 *
 *   Mail::to($user)->send(new OrderShipped($order));
 *   Mail::raw('user@x.dev', 'Hi', 'plain body');
 *   Mail::send((new \Nitro\Mail\Message())->to(...)->subject(...)->html(...));
 *
 * @method static \Nitro\Mail\PendingMail to(mixed $users, ?string $name = null)
 * @method static \Nitro\Mail\PendingMail cc(mixed $users, ?string $name = null)
 * @method static \Nitro\Mail\PendingMail bcc(mixed $users, ?string $name = null)
 * @method static \Nitro\Mail\PendingMail usingMailer(string $name)
 * @method static void queue(\Nitro\Mail\Mailable $mailable)
 * @method static void later(int $delay, \Nitro\Mail\Mailable $mailable)
 * @method static void send(\Nitro\Mail\Message|\Nitro\Mail\Mailable $message)
 * @method static void raw(string $to, string $subject, string $text)
 * @method static void html(string $to, string $subject, string $html)
 * @method static \Nitro\Mail\Mailer mailer(?string $name = null)
 */
class Mail extends Facade
{
    use \Nitro\Facades\Concerns\SwapsForFakes;

    protected static function getFacadeAccessor(): string
    {
        return 'mail';
    }

    /** Record mail instead of sending it. */
    public static function fake(): \Nitro\Mail\MailFake
    {
        return static::swap(new \Nitro\Mail\MailFake());
    }
}
