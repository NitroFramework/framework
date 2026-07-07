<?php

namespace Nitro\Mail\Transports;

use Nitro\Mail\Contracts\Transport;
use Nitro\Mail\Message;

/** Appends messages to a log file instead of transmitting — local dev default. */
class LogTransport implements Transport
{
    public function __construct(protected string $logPath) {}

    public function send(Message $message): void
    {
        $dir = dirname($this->logPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $to = implode(', ', array_map(static fn ($r) => $r['address'], $message->to));
        $body = $message->html ?? $message->text ?? '';

        $entry = sprintf(
            "[%s] mail to <%s>\nSubject: %s\n\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $message->subject,
            $body,
            str_repeat('-', 72),
        );

        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
