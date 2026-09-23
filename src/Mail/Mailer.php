<?php

namespace Nitro\Mail;

use Nitro\Events\Contracts\Dispatcher;
use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\Contracts\Transport;
use Nitro\Mail\Events\MessageSending;
use Nitro\Mail\Events\MessageSent;

/** Sends messages through a Transport, applying the configured default sender. */
class Mailer implements MailerContract
{
    public function __construct(
        protected Transport $transport,
        protected ?array $from = null,
        protected ?Dispatcher $events = null,
    ) {}

    public function send(Message|Mailable $message): void
    {
        if ($message instanceof Mailable) {
            $message = $message->buildMessage();
        }

        if ($message->from === null && $this->from !== null) {
            $message->from($this->from['address'], $this->from['name'] ?? null);
        }

        // A listener here can stamp a header onto every message the
        // application sends, or redirect mail somewhere safe on staging.
        // Returning false stops the send outright.
        if ($this->events?->until(MessageSending::class, new MessageSending($message)) === false) {
            return;
        }

        $this->transport->send($message);

        // Handed over, not delivered. An application that logs its mail writes
        // the row from here, because this is the only moment it knows a
        // message existed at all.
        $this->events?->dispatch(new MessageSent($message));
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
