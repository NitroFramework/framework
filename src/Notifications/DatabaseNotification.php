<?php

namespace Nitro\Notifications;

use Nitro\Database\Model\Model;
use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Relations\MorphTo;

/**
 * One row the database channel wrote.
 *
 * What a bell menu reads: the notifications belonging to a user, newest
 * first, with the unread ones distinguishable.
 */
class DatabaseNotification extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'id';

    /** The channel assigns the key, so nothing counts up. */
    protected $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /** Whoever the notification is for. */
    public function notifiable(): MorphTo
    {
        return $this->morphTo('notifiable');
    }

    /** Mark it read, unless it already is. */
    public function markAsRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill(['read_at' => date('Y-m-d H:i:s')])->save();
    }

    /** Mark it unread, unless it already is. */
    public function markAsUnread(): void
    {
        if ($this->read_at === null) {
            return;
        }

        $this->forceFill(['read_at' => null])->save();
    }

    public function read(): bool
    {
        return $this->read_at !== null;
    }

    public function unread(): bool
    {
        return $this->read_at === null;
    }

    public function scopeRead(ModelBuilder $query): void
    {
        $query->whereNotNull('read_at');
    }

    public function scopeUnread(ModelBuilder $query): void
    {
        $query->whereNull('read_at');
    }
}
