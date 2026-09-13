<?php

namespace Tests\Unit\Mail;

use InvalidArgumentException;
use Nitro\Mail\Message;
use Nitro\Mail\Transports\LogTransport;
use PHPUnit\Framework\TestCase;

/**
 * Custom headers on a message.
 *
 * They are how a message that has left the building is recognised again: a
 * delivery log answering "did this learner get their certificate email?", or a
 * provider's bounce webhook arriving days later with nothing but the message's
 * own headers to identify it by.
 */
class MessageHeadersTest extends TestCase
{
    private function message(): Message
    {
        return (new Message())
            ->from('noreply@example.test')
            ->to('ellie@example.test')
            ->subject('Your certificate')
            ->html('<p>Well done.</p>');
    }

    public function test_a_header_is_set_and_read_back(): void
    {
        $message = $this->message()->header('X-App-Template', 'certificate.issued');

        $this->assertSame('certificate.issued', $message->header('X-App-Template'));
    }

    public function test_an_unset_header_is_null(): void
    {
        $this->assertNull($this->message()->header('X-Nothing'));
    }

    public function test_several_can_be_set_at_once(): void
    {
        $message = $this->message()->withHeaders([
            'X-App-Template' => 'certificate.issued',
            'X-App-Recipient-Id' => '42',
        ]);

        $this->assertSame('42', $message->header('X-App-Recipient-Id'));
        $this->assertCount(2, $message->headers);
    }

    public function test_a_value_carrying_a_line_break_is_refused(): void
    {
        // Stripping the break would keep a forged value in the header; the
        // point is that it does not get there at all.
        $this->expectException(InvalidArgumentException::class);

        $this->message()->header('X-App-Template', "ok\r\nBcc: somebody@elsewhere.test");
    }

    public function test_a_name_carrying_a_line_break_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message()->header("X-App\nBcc", 'value');
    }

    public function test_an_empty_header_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message()->header('X-App-Template', '   ');
    }

    public function test_the_log_transport_writes_them_out(): void
    {
        $path = sys_get_temp_dir() . '/nitro-mail-' . bin2hex(random_bytes(4)) . '.log';

        $message = $this->message()->header('X-App-Template', 'certificate.issued');

        (new LogTransport($path))->send($message);

        $written = (string) file_get_contents($path);
        unlink($path);

        // A local log that drops them cannot answer the question the log
        // exists for.
        $this->assertStringContainsString('X-App-Template: certificate.issued', $written);
        $this->assertStringContainsString('Well done.', $written);
    }
}
