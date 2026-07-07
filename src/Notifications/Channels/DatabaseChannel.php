<?php

namespace Nitro\Notifications\Channels;

use Nitro\Database\DB;
use Nitro\Notifications\Contracts\Channel;
use Nitro\Notifications\Notification;
use RuntimeException;

/** Persists a notification's toDatabase() payload to the notifications table. */
class DatabaseChannel implements Channel
{
    public function __construct(protected string $table = 'notifications') {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDatabase')) {
            throw new RuntimeException(get_class($notification) . ' must define toDatabase() to use the database channel.');
        }

        $now = date('Y-m-d H:i:s');

        DB::table($this->table)->insert([
            'id' => bin2hex(random_bytes(16)),
            'type' => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => (string) (method_exists($notifiable, 'getKey') ? $notifiable->getKey() : ($notifiable->id ?? '')),
            'data' => json_encode($notification->toDatabase($notifiable)),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
