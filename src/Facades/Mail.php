<?php

namespace Nitro\Facades;

/**
 * Mail facade — send mail through the configured mailer.
 *
 *   Mail::send('user@x.dev', 'Hi', 'plain body');
 *   Mail::html('user@x.dev', 'Hi', '<b>rich</b>');
 *   Mail::sendMessage((new \Nitro\Mail\Message())->to(...)->subject(...)->html(...));
 *
 * @method static void send(string $to, string $subject, string $body)
 * @method static void html(string $to, string $subject, string $html)
 * @method static void sendMessage(\Nitro\Mail\Message $message)
 * @method static \Nitro\Mail\Mailer mailer(?string $name = null)
 */
class Mail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mail';
    }
}
