<?php

namespace Nitro\Notifications\Contracts;

use Nitro\Notifications\Notification;

/** Delivers a notification to a notifiable over one medium. */
interface Channel
{
    public function send(object $notifiable, Notification $notification): void;
}
