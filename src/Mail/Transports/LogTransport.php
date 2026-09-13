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

        $to = implode(', ', array_map(static fn ($recipient) => $recipient['address'], $message->to));
        $body = $message->html ?? $message->text ?? '';

        // Custom headers are written out too. They are what ties a message to
        // the thing that produced it, and a local log that drops them cannot
        // answer the question the log exists for.
        $headers = '';

        foreach ($message->headers as $name => $value) {
            $headers .= $name . ': ' . $value . "\n";
        }

        $entry = sprintf(
            "[%s] mail to <%s>\nSubject: %s\n%s\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $message->subject,
            $headers,
            $body,
            str_repeat('-', 72),
        );

        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
