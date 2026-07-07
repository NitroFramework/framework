<?php

namespace Nitro\Mail\Contracts;

use Nitro\Mail\Message;

/** Delivers a Message (log, array, SMTP, …). */
interface Transport
{
    public function send(Message $message): void;
}
