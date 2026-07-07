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

    public function send(Message $message): void
    {
        if ($message->from === null && $this->from !== null) {
            $message->from($this->from['address'], $this->from['name'] ?? null);
        }

        $this->transport->send($message);
    }

    public function raw(string $to, string $subject, string $text): void
    {
        $this->send((new Message())->to($to)->subject($subject)->text($text));
    }

    public function html(string $to, string $subject, string $html): void
    {
        $this->send((new Message())->to($to)->subject($subject)->html($html));
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
