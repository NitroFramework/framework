<?php

namespace Tests\Unit\Mail;

use Nitro\Events\Dispatcher;
use Nitro\Mail\Events\MessageSending;
use Nitro\Mail\Events\MessageSent;
use Nitro\Mail\Mailer;
use Nitro\Mail\Message;
use Nitro\Mail\Transports\ArrayTransport;
use PHPUnit\Framework\TestCase;

/**
 * The seam a mail log hangs off.
 *
 * An application that has to answer "did this person get their certificate
 * email?" needs to write a row at the moment of sending, and the only other
 * place to do that is inside a wrapper around the mailer that every caller
 * then has to remember to use.
 */
class MailerEventsTest extends TestCase
{
    private function message(): Message
    {
        return (new Message())
            ->to('ellie@example.test')
            ->subject('Your certificate')
            ->html('<p>Well done.</p>');
    }

    public function test_sending_raises_both_events_in_order(): void
    {
        $events = new Dispatcher();
        $seen = [];

        $events->listen(MessageSending::class, function () use (&$seen) { $seen[] = 'sending'; });
        $events->listen(MessageSent::class, function () use (&$seen) { $seen[] = 'sent'; });

        (new Mailer(new ArrayTransport(), ['address' => 'noreply@example.test'], $events))
            ->send($this->message());

        $this->assertSame(['sending', 'sent'], $seen);
    }

    public function test_a_listener_can_stop_the_send(): void
    {
        $events = new Dispatcher();
        $transport = new ArrayTransport();

        // What a staging environment does rather than mailing real customers.
        $events->listen(MessageSending::class, fn () => false);

        (new Mailer($transport, null, $events))->send($this->message());

        $this->assertSame([], $transport->messages);
    }

    public function test_a_stopped_send_raises_no_sent_event(): void
    {
        $events = new Dispatcher();
        $sent = 0;

        $events->listen(MessageSending::class, fn () => false);
        $events->listen(MessageSent::class, function () use (&$sent) { $sent++; });

        (new Mailer(new ArrayTransport(), null, $events))->send($this->message());

        // Logging a message that never left would be worse than not logging.
        $this->assertSame(0, $sent);
    }

    public function test_the_message_reaches_the_listener(): void
    {
        $events = new Dispatcher();
        $subject = null;

        $events->listen(MessageSent::class, function (MessageSent $event) use (&$subject) {
            $subject = $event->message->subject;
        });

        (new Mailer(new ArrayTransport(), null, $events))->send($this->message());

        $this->assertSame('Your certificate', $subject);
    }

    public function test_a_mailer_with_no_dispatcher_still_sends(): void
    {
        $transport = new ArrayTransport();

        (new Mailer($transport))->send($this->message());

        $this->assertCount(1, $transport->messages);
    }
}
