<?php

namespace Nitro\Mail;

use Nitro\Mail\Contracts\Mailer;

/**
 * Development mail driver: appends each message to a log file instead of
 * transmitting it. Lets the password-reset and email-verification flows work
 * end to end locally (open the log, copy the link) with zero dependencies and
 * nothing leaving the machine. Swap the 'mailer' binding for a real transport
 * in production.
 */
class LogMailer implements Mailer
{
    public function __construct(protected string $logPath) {}

    public function send(string $to, string $subject, string $body): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = sprintf(
            "[%s] mail to <%s>\nSubject: %s\n\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body,
            str_repeat('-', 72),
        );

        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
