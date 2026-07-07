<?php

namespace Nitro\Facades;

/**
 * Notification facade — send a notification to one or many notifiables.
 *
 *   Notification::send($user, new InvoicePaid($invoice));
 *
 * @method static void send(object|iterable $notifiables, \Nitro\Notifications\Notification $notification)
 */
class Notification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'notification';
    }
}
