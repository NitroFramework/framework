<?php

namespace Nitro\Facades;

/**
 * Mail facade — send mail through the configured mailer.
 *
 *   Mail::raw('user@x.dev', 'Hi', 'plain body');
 *   Mail::html('user@x.dev', 'Hi', '<b>rich</b>');
 *   Mail::send((new \Nitro\Mail\Message())->to(...)->subject(...)->html(...));
 *
 * @method static void send(\Nitro\Mail\Message $message)
 * @method static void raw(string $to, string $subject, string $text)
 * @method static void html(string $to, string $subject, string $html)
 * @method static \Nitro\Mail\Mailer mailer(?string $name = null)
 */
class Mail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mail';
    }
}
