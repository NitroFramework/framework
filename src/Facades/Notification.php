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
    use \Nitro\Facades\Concerns\SwapsForFakes;

    protected static function getFacadeAccessor(): string
    {
        return 'notification';
    }

    /** Record notifications instead of delivering them. */
    public static function fake(): \Nitro\Notifications\NotificationFake
    {
        return static::swap(new \Nitro\Notifications\NotificationFake());
    }
}
