<?php

namespace Nitro\Notifications;

/**
 * A model that can be notified, and that keeps the notifications sent to it.
 *
 * The two halves are separable: {@see RoutesNotifications} alone is
 * enough for something that is reached but keeps no record.
 */
trait Notifiable
{
    use HasDatabaseNotifications;
    use RoutesNotifications;
}
