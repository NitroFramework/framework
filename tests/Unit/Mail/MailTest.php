<?php

namespace Tests\Unit\Mail;

use InvalidArgumentException;
use Nitro\Mail\Mailer;
use Nitro\Mail\MailManager;
use Nitro\Mail\Message;
use Nitro\Mail\Transports\ArrayTransport;
use Nitro\Mail\Transports\SmtpTransport;
use PHPUnit\Framework\TestCase;

class MailTest extends TestCase
{
    private function manager(): MailManager
    {
        return new MailManager([
            'default' => 'array',
            'from' => ['address' => 'app@nitro.dev', 'name' => 'Nitro'],
            'mailers' => [
                'array' => ['transport' => 'array'],
                'smtp'  => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025],
            ],
        ]);
    }

    public function test_message_builds_fluently(): void
    {
        $m = (new Message())
            ->from('from@x.dev', 'Sender')
            ->to('a@x.dev')->to('b@x.dev', 'Bee')
            ->cc('c@x.dev')->bcc('d@x.dev')
            ->subject('Hi')->html('<b>hi</b>')->text('hi');

        $this->assertSame('from@x.dev', $m->from['address']);
        $this->assertCount(2, $m->to);
        $this->assertEqualsCanonicalizing(['a@x.dev', 'b@x.dev', 'c@x.dev', 'd@x.dev'], $m->recipients());
    }

    public function test_array_transport_collects_messages(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, ['address' => 'app@nitro.dev', 'name' => 'Nitro']);

        $mailer->send('user@x.dev', 'Welcome', 'plain body');

        $this->assertCount(1, $transport->messages);
        $this->assertSame('Welcome', $transport->messages[0]->subject);
        $this->assertSame('plain body', $transport->messages[0]->text);
    }

    public function test_mailer_applies_default_from(): void
    {
        $transport = new ArrayTransport();
        (new Mailer($transport, ['address' => 'app@nitro.dev', 'name' => 'Nitro']))
            ->html('user@x.dev', 'Hi', '<p>hi</p>');

        $sent = $transport->messages[0];
        $this->assertSame('app@nitro.dev', $sent->from['address']);
        $this->assertSame('<p>hi</p>', $sent->html);
    }

    public function test_manager_resolves_and_proxies_default_mailer(): void
    {
        $manager = $this->manager();

        $this->assertInstanceOf(Mailer::class, $manager->mailer());
        $this->assertInstanceOf(SmtpTransport::class, $manager->mailer('smtp')->transport());

        // Proxy to the default (array) mailer.
        $manager->send('x@x.dev', 'Proxied', 'body');
        $this->assertCount(1, $manager->mailer('array')->transport()->messages);
    }

    public function test_manager_rejects_unknown_mailer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager()->mailer('carrier-pigeon');
    }

    // ─── real SMTP delivery via MailHog ───────────────────

    private function mailhogUp(): bool
    {
        $s = @fsockopen('127.0.0.1', 1025, $e, $m, 0.5);
        if (! $s) {
            return false;
        }
        fclose($s);

        return true;
    }

    public function test_smtp_transport_delivers_to_a_real_server(): void
    {
        if (! $this->mailhogUp()) {
            $this->markTestSkipped('MailHog not running on 127.0.0.1:1025');
        }

        // Clear MailHog, send, then verify via its API.
        @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));

        $subject = 'Nitro SMTP ' . bin2hex(random_bytes(4));
        (new SmtpTransport('127.0.0.1', 1025))->send(
            (new Message())
                ->from('app@nitro.dev', 'Nitro')
                ->to('user@example.com')
                ->subject($subject)
                ->html('<h1>Hello from Nitro</h1>')
        );

        $inbox = json_decode((string) @file_get_contents('http://127.0.0.1:8025/api/v2/messages'), true);
        $subjects = array_map(
            static fn ($item) => $item['Content']['Headers']['Subject'][0] ?? '',
            $inbox['items'] ?? [],
        );

        $this->assertContains($subject, $subjects, 'the message reached MailHog');
    }
}
