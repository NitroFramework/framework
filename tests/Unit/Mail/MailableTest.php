<?php

namespace Tests\Unit\Mail;

use Nitro\Mail\Attachment;
use Nitro\Mail\Mailable;
use Nitro\Mail\Mailables\Address;
use Nitro\Mail\Mailables\Content;
use Nitro\Mail\Mailables\Envelope;
use Nitro\Mail\Mailables\Headers;
use Nitro\Mail\Message;
use PHPUnit\Framework\TestCase;

/** One kind of email, declared as a class. */
class MailableTest extends TestCase
{
    // ── The four declarations ─────────────────────────────────────────

    public function test_the_envelope_names_the_sender_and_recipients(): void
    {
        $message = (new DeclaredMailable())->buildMessage();

        $this->assertSame('shop@example.com', $message->from['address']);
        $this->assertSame('The Shop', $message->from['name']);
        $this->assertSame('ada@example.com', $message->to[0]['address']);
        $this->assertSame('Your order is on its way', $message->subject);
    }

    public function test_the_content_supplies_the_body(): void
    {
        $message = (new InlineMailable())->buildMessage();

        $this->assertSame('<p>Shipped</p>', $message->html);
    }

    public function test_attachments_are_read_when_the_message_is_built(): void
    {
        $message = (new AttachingMailable())->buildMessage();

        $this->assertCount(1, $message->attachments);
        $this->assertSame('invoice.txt', $message->attachments[0]['name']);
        $this->assertSame('the invoice', $message->attachments[0]['content']);
    }

    public function test_headers_reach_the_message(): void
    {
        $message = (new ThreadedMailable())->buildMessage();

        $this->assertSame('<abc@example.com>', $message->header('Message-Id'));
        $this->assertSame('<first@example.com>', $message->header('References'));
        $this->assertSame('shipped', $message->header('X-Template'));
    }

    /** A mailable with nothing declared still builds. */
    public function test_an_empty_mailable_builds_a_message(): void
    {
        $message = (new BareMailable())->buildMessage();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame([], $message->to);
    }

    // ── Naming ────────────────────────────────────────────────────────

    /** A mailable with no subject is named after its class. */
    public function test_the_class_name_becomes_the_subject(): void
    {
        $this->assertSame('Bare Mailable', (new BareMailable())->buildMessage()->subject);
    }

    public function test_a_declared_subject_wins(): void
    {
        $this->assertSame('Your order is on its way', (new DeclaredMailable())->buildMessage()->subject);
    }

    public function test_a_subject_set_at_the_call_site_wins_over_the_declaration(): void
    {
        $mailable = (new DeclaredMailable())->subject('Rush delivery');

        $this->assertSame('Rush delivery', $mailable->buildMessage()->subject);
    }

    // ── The fluent API ────────────────────────────────────────────────

    public function test_recipients_can_be_added_at_the_call_site(): void
    {
        $message = (new BareMailable())
            ->to('ada@example.com', 'Ada')
            ->cc('grace@example.com')
            ->bcc('audit@example.com')
            ->replyTo('support@example.com')
            ->buildMessage();

        $this->assertSame('Ada', $message->to[0]['name']);
        $this->assertSame('grace@example.com', $message->cc[0]['address']);
        $this->assertSame('audit@example.com', $message->bcc[0]['address']);
        $this->assertSame('support@example.com', $message->replyTo[0]['address']);
    }

    /** Several reply-to addresses are all kept. */
    public function test_more_than_one_reply_to_is_carried(): void
    {
        $message = (new BareMailable())
            ->replyTo('one@example.com')
            ->replyTo('two@example.com')
            ->buildMessage();

        $this->assertCount(2, $message->replyTo);
    }

    /** A list of addresses is accepted where one is. */
    public function test_a_list_of_recipients_is_accepted(): void
    {
        $message = (new BareMailable())
            ->to(['ada@example.com', 'grace@example.com'])
            ->buildMessage();

        $this->assertCount(2, $message->to);
    }

    /**
     * An object carrying an email is read for one.
     *
     * A call site has a user in hand, not a string.
     */
    public function test_an_object_is_read_for_its_email_and_name(): void
    {
        $message = (new BareMailable())->to(new Recipient())->buildMessage();

        $this->assertSame('ada@example.com', $message->to[0]['address']);
        $this->assertSame('Ada', $message->to[0]['name']);
    }

    public function test_an_object_without_an_email_is_refused_by_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/stdClass/');

        (new BareMailable())->to(new \stdClass())->buildMessage();
    }

    public function test_the_declaration_and_the_call_site_both_contribute(): void
    {
        $message = (new DeclaredMailable())->cc('boss@example.com')->buildMessage();

        $this->assertSame('ada@example.com', $message->to[0]['address']);
        $this->assertSame('boss@example.com', $message->cc[0]['address']);
    }

    // ── View data ─────────────────────────────────────────────────────

    /** Public properties reach the view by name. */
    public function test_public_properties_become_view_data(): void
    {
        $data = (new PropertyMailable('SO-1'))->buildViewData();

        $this->assertSame('SO-1', $data['reference']);
    }

    public function test_the_mailables_own_properties_are_not_view_data(): void
    {
        $data = (new PropertyMailable('SO-1'))->buildViewData();

        $this->assertArrayNotHasKey('subject', $data);
        $this->assertArrayNotHasKey('attachments', $data);
    }

    public function test_with_adds_data_alongside_the_properties(): void
    {
        $data = (new PropertyMailable('SO-1'))->with('tracking', 'XYZ')->buildViewData();

        $this->assertSame('SO-1', $data['reference']);
        $this->assertSame('XYZ', $data['tracking']);
    }

    // ── Asking ────────────────────────────────────────────────────────

    public function test_a_mailable_can_be_asked_who_it_is_addressed_to(): void
    {
        $mailable = (new DeclaredMailable());
        $mailable->buildMessage();

        $this->assertTrue($mailable->hasTo('ada@example.com'));
        $this->assertTrue($mailable->hasFrom('shop@example.com', 'The Shop'));
        $this->assertTrue($mailable->hasSubject('Your order is on its way'));
        $this->assertFalse($mailable->hasTo('nobody@example.com'));
    }

    public function test_tags_and_metadata_travel_as_headers(): void
    {
        $message = (new BareMailable())
            ->tag('orders')
            ->metadata('order_id', 42)
            ->buildMessage();

        $this->assertSame('orders', $message->header('X-Tags'));
        $this->assertSame('42', $message->header('X-Metadata-order_id'));
    }

    // ── Addresses ─────────────────────────────────────────────────────

    /** A line break in an address is header injection, not a typo. */
    public function test_an_address_with_a_line_break_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Address("ada@example.com\r\nBcc: someone@else.com");
    }

    public function test_an_address_renders_with_its_name(): void
    {
        $this->assertSame('Ada <ada@example.com>', (new Address('ada@example.com', 'Ada'))->toString());
        $this->assertSame('ada@example.com', (new Address('ada@example.com'))->toString());
    }

    // ── The envelope on its own ───────────────────────────────────────

    public function test_an_envelope_accepts_strings_as_addresses(): void
    {
        $envelope = new Envelope(from: 'shop@example.com', to: ['ada@example.com']);

        $this->assertTrue($envelope->isFrom('shop@example.com'));
        $this->assertTrue($envelope->hasTo('ada@example.com'));
    }

    public function test_an_envelope_builds_fluently(): void
    {
        $envelope = (new Envelope())
            ->to('ada@example.com', 'Ada')
            ->cc('grace@example.com')
            ->subject('Hello')
            ->tag('greeting')
            ->metadata('id', 7);

        $this->assertTrue($envelope->hasTo('ada@example.com', 'Ada'));
        $this->assertTrue($envelope->hasCc('grace@example.com'));
        $this->assertTrue($envelope->hasSubject('Hello'));
        $this->assertTrue($envelope->hasTag('greeting'));
        $this->assertTrue($envelope->hasMetadata('id', 7));
    }

    // ── Attachments on their own ──────────────────────────────────────

    /** Nothing is read until the attachment is added to a message. */
    public function test_an_attachment_reads_its_file_only_when_attached(): void
    {
        $read = false;

        $attachment = Attachment::fromData(function () use (&$read): string {
            $read = true;

            return 'built';
        }, 'report.csv');

        $this->assertFalse($read, 'building the attachment must not build the file');

        $attachment->attachTo(new Message());

        $this->assertTrue($read);
    }

    public function test_an_attachment_infers_its_type_from_the_name(): void
    {
        $message = new Message();

        Attachment::fromData(static fn (): string => 'x', 'report.csv')->attachTo($message);

        $this->assertSame('text/csv', $message->attachments[0]['mime']);
    }

    public function test_a_stated_type_wins_over_the_inferred_one(): void
    {
        $message = new Message();

        Attachment::fromData(static fn (): string => 'x', 'report.csv')
            ->withMime('application/vnd.ms-excel')
            ->attachTo($message);

        $this->assertSame('application/vnd.ms-excel', $message->attachments[0]['mime']);
    }

    public function test_an_unreadable_path_is_refused_by_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nowhere/');

        Attachment::fromPath('/nowhere/at/all.pdf')->attachTo(new Message());
    }

    public function test_an_attachment_can_be_renamed(): void
    {
        $message = new Message();

        Attachment::fromData(static fn (): string => 'x', 'tmp-9f2.csv')
            ->as('report.csv')
            ->attachTo($message);

        $this->assertSame('report.csv', $message->attachments[0]['name']);
    }
}

// ── Mailables ─────────────────────────────────────────────────────────

class BareMailable extends Mailable {}

class DeclaredMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('shop@example.com', 'The Shop'),
            to: ['ada@example.com'],
            subject: 'Your order is on its way',
        );
    }
}

class InlineMailable extends Mailable
{
    public function content(): Content
    {
        return new Content(htmlString: '<p>Shipped</p>');
    }
}

class AttachingMailable extends Mailable
{
    public function attachments(): array
    {
        return [Attachment::fromData(static fn (): string => 'the invoice', 'invoice.txt')];
    }
}

class ThreadedMailable extends Mailable
{
    public function headers(): Headers
    {
        return new Headers(
            messageId: 'abc@example.com',
            references: ['first@example.com'],
            text: ['X-Template' => 'shipped'],
        );
    }
}

class PropertyMailable extends Mailable
{
    public function __construct(public string $reference) {}
}

// ── Doubles ───────────────────────────────────────────────────────────

class Recipient
{
    public string $email = 'ada@example.com';

    public string $name = 'Ada';
}
