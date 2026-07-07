<?php

namespace Nitro\Mail;

use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\Contracts\Transport;

/** Sends messages through a Transport, applying the configured default sender. */
class Mailer implements MailerContract
{
    public function __construct(
        protected Transport $transport,
        protected ?array $from = null,
    ) {}

    public function send(string $to, string $subject, string $body): void
    {
        $this->sendMessage((new Message())->to($to)->subject($subject)->text($body));
    }

    public function html(string $to, string $subject, string $html): void
    {
        $this->sendMessage((new Message())->to($to)->subject($subject)->html($html));
    }

    public function sendMessage(Message $message): void
    {
        if ($message->from === null && $this->from !== null) {
            $message->from($this->from['address'], $this->from['name'] ?? null);
        }

        $this->transport->send($message);
    }

    /** A fresh message to build fluently. */
    public function message(): Message
    {
        return new Message();
    }

    public function transport(): Transport
    {
        return $this->transport;
    }
}
