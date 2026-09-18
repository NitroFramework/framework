<?php

namespace Tests\Unit\Notifications;

use Nitro\Notifications\AnonymousNotifiable;
use Nitro\Notifications\Messages\BroadcastMessage;
use Nitro\Notifications\Messages\DatabaseMessage;
use Nitro\Notifications\Messages\MailMessage;
use PHPUnit\Framework\TestCase;

/**
 * The message objects a notification builds.
 *
 * A notification describes what it means and the channel renders it, so these
 * are checked by the structure they produce rather than by any markup.
 */
class MailMessageTest extends TestCase
{
    // ─── Line placement ───────────────────────────────────

    /** Where a line lands depends on whether the action has been set yet. */
    public function test_lines_split_around_the_action(): void
    {
        $message = (new MailMessage())
            ->line('Before one.')
            ->line('Before two.')
            ->action('View Order', 'https://example.com/orders/1')
            ->line('After.');

        $this->assertSame(['Before one.', 'Before two.'], $message->introLines);
        $this->assertSame(['After.'], $message->outroLines);
    }

    public function test_a_message_with_no_action_keeps_every_line_before_it(): void
    {
        $message = (new MailMessage())->line('One.')->line('Two.');

        $this->assertSame(['One.', 'Two.'], $message->introLines);
        $this->assertSame([], $message->outroLines);
    }

    /** A line written across several source lines should read as one sentence. */
    public function test_whitespace_in_a_line_is_collapsed(): void
    {
        $message = (new MailMessage())->line("Your order\n    has    shipped.");

        $this->assertSame(['Your order has shipped.'], $message->introLines);
    }

    public function test_lines_accepts_a_list(): void
    {
        $message = (new MailMessage())->lines(['One.', 'Two.']);

        $this->assertSame(['One.', 'Two.'], $message->introLines);
    }

    // ─── Action ───────────────────────────────────────────

    public function test_the_action_carries_its_text_and_url(): void
    {
        $message = (new MailMessage())->action('View Order', 'https://example.com/o/1');

        $this->assertSame('View Order', $message->action->text);
        $this->assertSame('https://example.com/o/1', $message->action->url);
    }

    // ─── Level ────────────────────────────────────────────

    public function test_the_level_defaults_to_info_and_can_be_set(): void
    {
        $this->assertSame('info', (new MailMessage())->level);
        $this->assertSame('success', (new MailMessage())->success()->level);
        $this->assertSame('error', (new MailMessage())->error()->level);
        $this->assertSame('warning', (new MailMessage())->level('warning')->level);
    }

    // ─── Addressing ───────────────────────────────────────

    public function test_recipients_accumulate(): void
    {
        $message = (new MailMessage())
            ->to('a@example.com')
            ->to(['b@example.com', 'c@example.com'])
            ->cc('d@example.com')
            ->bcc('e@example.com');

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $message->to);
        $this->assertSame(['d@example.com'], $message->cc);
        $this->assertSame(['e@example.com'], $message->bcc);
    }

    public function test_a_view_of_your_own_takes_over_rendering(): void
    {
        $message = (new MailMessage())->view('emails.custom', ['order' => 7]);

        $this->assertSame('emails.custom', $message->view);
        $this->assertSame(7, $message->data()['order']);
    }

    /** The layout reads the message through data(), so the shape is pinned. */
    public function test_data_carries_everything_the_layout_needs(): void
    {
        $data = (new MailMessage())
            ->subject('Order shipped')
            ->greeting('Hello Ada')
            ->line('It is on its way.')
            ->action('Track', 'https://example.com/t')
            ->line('Thanks.')
            ->salutation('Cheers,')
            ->success()
            ->data();

        $this->assertSame('Order shipped', $data['subject']);
        $this->assertSame('Hello Ada', $data['greeting']);
        $this->assertSame('Cheers,', $data['salutation']);
        $this->assertSame('success', $data['level']);
        $this->assertSame(['It is on its way.'], $data['introLines']);
        $this->assertSame(['Thanks.'], $data['outroLines']);
        $this->assertSame('Track', $data['actionText']);
        $this->assertSame('https://example.com/t', $data['actionUrl']);
    }

    // ─── Other messages ───────────────────────────────────

    public function test_a_database_message_holds_its_payload(): void
    {
        $this->assertSame(['order' => 1], (new DatabaseMessage(['order' => 1]))->data);
    }

    public function test_a_broadcast_message_can_name_its_event_and_queue(): void
    {
        $message = (new BroadcastMessage(['a' => 1]))
            ->event('order.shipped')
            ->onQueue('broadcasts');

        $this->assertSame(['a' => 1], $message->data);
        $this->assertSame('order.shipped', $message->event);
        $this->assertSame('broadcasts', $message->queue);
    }

    // ─── Anonymous recipients ─────────────────────────────

    public function test_an_anonymous_recipient_routes_per_channel(): void
    {
        $recipient = (new AnonymousNotifiable())->route('mail', 'ops@example.com');

        $this->assertSame('ops@example.com', $recipient->routeNotificationFor('mail'));
        $this->assertNull($recipient->routeNotificationFor('database'));
    }

    /** The database channel writes against a model, so it cannot be anonymous. */
    public function test_the_database_channel_cannot_be_routed_anonymously(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a notifiable model');

        (new AnonymousNotifiable())->route('database', 'nowhere');
    }
}
