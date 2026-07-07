<?php

namespace Tests\Unit\Notifications;

use Nitro\Container\Container;
use Nitro\Database\DB;
use Nitro\Database\Schema\SchemaBuilder as Schema;
use Nitro\Mail\Mailer;
use Nitro\Mail\Message;
use Nitro\Mail\Transports\ArrayTransport;
use Nitro\Notifications\ChannelManager;
use Nitro\Notifications\Channels\DatabaseChannel;
use Nitro\Notifications\Channels\MailChannel;
use Nitro\Notifications\Notifiable;
use Nitro\Notifications\Notification;
use Nitro\Notifications\NotificationSender;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    private function notification(): Notification
    {
        return new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['mail', 'database'];
            }

            public function toMail(object $notifiable): Message
            {
                return (new Message())->subject('Invoice')->text('Your invoice is ready.');
            }

            public function toDatabase(object $notifiable): array
            {
                return ['invoice' => 42];
            }
        };
    }

    private function notifiable(): object
    {
        return new class {
            use Notifiable;

            public string $email = 'user@x.dev';

            public function getKey(): int
            {
                return 7;
            }
        };
    }

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }
        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::create('notifications', function ($t) {
            $t->string('id');
            $t->primary('id');
            $t->string('type');
            $t->string('notifiable_type');
            $t->string('notifiable_id');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    public function test_mail_channel_sends_with_the_routed_recipient(): void
    {
        $transport = new ArrayTransport();
        (new MailChannel(new Mailer($transport)))->send($this->notifiable(), $this->notification());

        $this->assertCount(1, $transport->messages);
        $this->assertSame('user@x.dev', $transport->messages[0]->to[0]['address']);
        $this->assertSame('Invoice', $transport->messages[0]->subject);
    }

    public function test_database_channel_persists_a_row(): void
    {
        (new DatabaseChannel())->send($this->notifiable(), $this->notification());

        $row = DB::table('notifications')->first();
        $this->assertSame('7', (string) $row->notifiable_id);
        $this->assertStringContainsString('invoice', $row->data);
    }

    public function test_notifiable_routes_mail_to_email(): void
    {
        $this->assertSame('user@x.dev', $this->notifiable()->routeNotificationFor('mail'));
        $this->assertNull($this->notifiable()->routeNotificationFor('sms'));
    }

    public function test_sender_dispatches_to_every_channel(): void
    {
        $container = new Container();
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport);
        $container->singleton('mailer', fn () => $mailer);

        (new NotificationSender(new ChannelManager($container)))
            ->send($this->notifiable(), $this->notification());

        $this->assertCount(1, $transport->messages, 'mail channel');
        $this->assertSame(1, DB::table('notifications')->count(), 'database channel');
    }
}
