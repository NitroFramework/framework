<?php

namespace Nitro\Notifications;

use Nitro\Database\Model\Relations\MorphMany;

/**
 * Reads the rows the database channel wrote for this model.
 *
 *     $user->unreadNotifications()->get();
 *     $user->notifications()->first()->markAsRead();
 */
trait HasDatabaseNotifications
{
    /** Every notification for this model, newest first. */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }

    /** Those that have been read. */
    public function readNotifications(): MorphMany
    {
        return $this->notifications()->whereNotNull('read_at');
    }

    /** Those that have not. */
    public function unreadNotifications(): MorphMany
    {
        return $this->notifications()->whereNull('read_at');
    }
}
