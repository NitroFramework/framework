<?php

namespace Tests\Unit\Mail;

use Nitro\Container\Container;
use Nitro\Foundation\Config;
use Nitro\Mail\Mailable;
use Nitro\Mail\Mailables\Content;
use Nitro\Mail\MailManager;
use Nitro\Mail\SendQueuedMailable;
use Nitro\Mail\Transports\ArrayTransport;
use Nitro\Queue\Contracts\ShouldQueue;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

/** Collecting recipients before the mailable is named. */
class PendingMailTest extends TestCase
{
    private MailManager $mail;
    private ArrayTransport $transport;
    private ArrayQueue $queue;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->transport = new ArrayTransport();

        $this->mail = new MailManager([
            'default' => 'array',
            'mailers' => ['array' => ['transport' => 'array']],
            'from' => ['address' => 'shop@example.com', 'name' => 'The Shop'],
        ]);

        $this->installTransport();
        $this->installQueue();
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    /** Put the readable transport behind the manager's default mailer. */
    private function installTransport(): void
    {
        $mailer = new \Nitro\Mail\Mailer($this->transport, ['address' => 'shop@example.com']);

        (fn () => $this->mailers['array'] = $mailer)->call($this->mail);
    }

    private function installQueue(): void
    {
        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => ['array' => ['driver' => 'array']],
            ],
        ]);

        $container = Container::getInstance();
        $container->instance(Config::class, $config);
        $container->instance(MailManager::class, $this->mail);

        $queues = new QueueManager(
            config: $config,
            syncQueue: static fn () => throw new \RuntimeException('not used'),
            redis: static fn () => throw new \RuntimeException('not used'),
            batches: static fn () => throw new \RuntimeException('not used'),
            batchCallbacks: static fn () => throw new \RuntimeException('not used'),
        );

        $this->queue = new ArrayQueue();
        $queues->extend('array', $this->queue);

        $container->instance(QueueManager::class, $queues);
    }

    /** The message the transport was handed. */
    private function sent(): \Nitro\Mail\Message
    {
        $messages = $this->transport->messages;

        $this->assertNotEmpty($messages, 'nothing was sent');

        return $messages[0];
    }

    // ── Sending ───────────────────────────────────────────────────────

    public function test_recipients_collected_first_reach_the_mailable(): void
    {
        $this->mail->to('ada@example.com', 'Ada')->send(new PlainMailable());

        $message = $this->sent();

        $this->assertSame('ada@example.com', $message->to[0]['address']);
        $this->assertSame('Ada', $message->to[0]['name']);
    }

    public function test_copies_are_collected_the_same_way(): void
    {
        $this->mail->to('ada@example.com')
            ->cc('grace@example.com')
            ->bcc('audit@example.com')
            ->send(new PlainMailable());

        $message = $this->sent();

        $this->assertSame('grace@example.com', $message->cc[0]['address']);
        $this->assertSame('audit@example.com', $message->bcc[0]['address']);
    }

    /** The configured sender fills in when the mailable names none. */
    public function test_the_default_sender_is_applied(): void
    {
        $this->mail->to('ada@example.com')->send(new PlainMailable());

        $this->assertSame('shop@example.com', $this->sent()->from['address']);
    }

    // ── Queueing ──────────────────────────────────────────────────────

    /** A mailable that asks to be queued is not sent now. */
    public function test_a_queued_mailable_goes_to_the_queue(): void
    {
        $this->mail->to('ada@example.com')->send(new QueuedMailable());

        $this->assertSame([], $this->transport->messages, 'nothing is sent in this process');
        $this->assertSame(1, $this->queue->size());
    }

    public function test_the_queued_job_carries_the_mailable_and_its_recipients(): void
    {
        $this->mail->to('ada@example.com')->send(new QueuedMailable());

        $job = $this->queue->pop()->decode()['instance'];

        $this->assertInstanceOf(SendQueuedMailable::class, $job);
        $this->assertTrue($job->mailable->hasTo('ada@example.com'));
    }

    /** The failed store names the mailable, not the job wrapping it. */
    public function test_the_queued_job_is_named_after_the_mailable(): void
    {
        $job = new SendQueuedMailable(new QueuedMailable());

        $this->assertSame(QueuedMailable::class, $job->displayName());
    }

    public function test_sending_the_job_delivers_the_mail(): void
    {
        $this->mail->to('ada@example.com')->send(new QueuedMailable());

        $this->queue->pop()->decode()['instance']->handle();

        $this->assertSame('ada@example.com', $this->sent()->to[0]['address']);
    }

    /** sendNow overrides what the mailable asked for. */
    public function test_send_now_ignores_the_queue(): void
    {
        $this->mail->to('ada@example.com')->sendNow(new QueuedMailable());

        $this->assertSame(0, $this->queue->size());
        $this->assertSame('ada@example.com', $this->sent()->to[0]['address']);
    }

    /** And queue() overrides the other way. */
    public function test_queue_forces_a_plain_mailable_onto_the_queue(): void
    {
        $this->mail->to('ada@example.com')->queue(new PlainMailable());

        $this->assertSame(1, $this->queue->size());
        $this->assertSame([], $this->transport->messages);
    }

    public function test_a_delayed_mailable_is_not_yet_eligible(): void
    {
        $this->mail->to('ada@example.com')->later(600, new PlainMailable());

        $this->assertSame(1, $this->queue->size());
        $this->assertNull($this->queue->pop(), 'the delay has not elapsed');
    }

    public function test_a_queued_mailable_can_name_its_queue(): void
    {
        $this->mail->to('ada@example.com')->queue((new PlainMailable())->onQueue('mail'));

        $this->assertSame(1, $this->queue->size('mail'));
    }
}

// ── Mailables ─────────────────────────────────────────────────────────

class PlainMailable extends Mailable
{
    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello</p>');
    }
}

class QueuedMailable extends PlainMailable implements ShouldQueue {}
