<?php

namespace Nitro\Mail\Transports;

use Nitro\Mail\Contracts\Transport;
use Nitro\Mail\Message;
use RuntimeException;

/**
 * Delivers messages over SMTP using a raw socket — no extension or library
 * needed. Supports AUTH LOGIN, implicit SSL and STARTTLS, and MIME bodies
 * (text, HTML, multipart/alternative, and attachments via multipart/mixed).
 */
class SmtpTransport implements Transport
{
    /** @var resource|null */
    protected $socket = null;

    public function __construct(
        protected string $host,
        protected int $port = 25,
        protected ?string $username = null,
        protected ?string $password = null,
        protected ?string $encryption = null, // null | 'tls' | 'ssl'
        protected float $timeout = 10.0,
        protected string $localDomain = 'localhost',
    ) {}

    public function send(Message $message): void
    {
        $this->connect();

        try {
            $from = $message->from['address'] ?? throw new RuntimeException('A message requires a from address.');

            $this->command("MAIL FROM:<{$from}>", 250);
            foreach ($message->recipients() as $recipient) {
                $this->command("RCPT TO:<{$recipient}>", 250);
            }

            $this->command('DATA', 354);
            $this->write($this->buildMime($message) . "\r\n.");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    // ─── connection ───────────────────────────────────────

    protected function connect(): void
    {
        $host = $this->encryption === 'ssl' ? "ssl://{$this->host}" : $this->host;

        $socket = @stream_socket_client("{$host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new RuntimeException("Could not connect to SMTP server {$this->host}:{$this->port} ({$errstr}).");
        }

        stream_set_timeout($socket, (int) $this->timeout);
        $this->socket = $socket;

        $this->expect(220);
        $this->command("EHLO {$this->localDomain}", 250);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', 220);
            if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable TLS on the SMTP connection.');
            }
            $this->command("EHLO {$this->localDomain}", 250);
        }

        if ($this->username !== null && $this->username !== '') {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334);
            $this->command(base64_encode((string) $this->password), 235);
        }
    }

    protected function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    // ─── protocol ─────────────────────────────────────────

    protected function command(string $command, int $expected): void
    {
        $this->write($command);
        $this->expect($expected);
    }

    protected function write(string $data): void
    {
        fwrite($this->socket, $data . "\r\n");
    }

    protected function expect(int $code): void
    {
        $response = $this->readResponse();
        if ((int) substr($response, 0, 3) !== $code) {
            throw new RuntimeException("Unexpected SMTP reply (wanted {$code}): {$response}");
        }
    }

    protected function readResponse(): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // A hyphen at position 3 (e.g. "250-") means more lines follow.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return trim($response);
    }

    // ─── MIME ─────────────────────────────────────────────

    protected function buildMime(Message $message): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($message->from),
            'To: ' . $this->formatAddressList($message->to),
            'Subject: ' . $this->encodeHeader($message->subject),
            'MIME-Version: 1.0',
        ];
        if ($message->cc !== []) {
            $headers[] = 'Cc: ' . $this->formatAddressList($message->cc);
        }
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $this->formatAddress($message->replyTo);
        }
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->localDomain . '>';

        [$contentHeaders, $body] = $this->buildBody($message);

        return implode("\r\n", array_merge($headers, $contentHeaders)) . "\r\n\r\n" . $body;
    }

    /** @return array{0: array<int, string>, 1: string} */
    protected function buildBody(Message $message): array
    {
        $alternative = $this->buildAlternative($message);

        if ($message->attachments === []) {
            return $alternative;
        }

        $boundary = 'mixed_' . bin2hex(random_bytes(8));
        $parts = ['--' . $boundary, $alternative[0][0], '', $alternative[1]];

        foreach ($message->attachments as $attachment) {
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $attachment['mime'] . '; name="' . $attachment['name'] . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $attachment['name'] . '"';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode($attachment['content']));
        }
        $parts[] = '--' . $boundary . '--';

        return [['Content-Type: multipart/mixed; boundary="' . $boundary . '"'], implode("\r\n", $parts)];
    }

    /** @return array{0: array<int, string>, 1: string} */
    protected function buildAlternative(Message $message): array
    {
        if ($message->html !== null && $message->text !== null) {
            $boundary = 'alt_' . bin2hex(random_bytes(8));
            $body = implode("\r\n", [
                '--' . $boundary,
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $message->text,
                '--' . $boundary,
                'Content-Type: text/html; charset=utf-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $message->html,
                '--' . $boundary . '--',
            ]);

            return [['Content-Type: multipart/alternative; boundary="' . $boundary . '"'], $body];
        }

        if ($message->html !== null) {
            return [['Content-Type: text/html; charset=utf-8', 'Content-Transfer-Encoding: 8bit'], $message->html];
        }

        return [['Content-Type: text/plain; charset=utf-8', 'Content-Transfer-Encoding: 8bit'], (string) $message->text];
    }

    protected function formatAddress(?array $address): string
    {
        if ($address === null) {
            return '';
        }

        // Belt-and-suspenders against header injection: the address is written
        // into a header verbatim, so strip any CR/LF even though Message already
        // rejects invalid addresses at the setter (an address may reach a
        // transport constructed directly).
        $email = str_replace(["\r", "\n"], '', (string) $address['address']);

        return $address['name']
            ? $this->encodeHeader($address['name']) . ' <' . $email . '>'
            : $email;
    }

    protected function formatAddressList(array $addresses): string
    {
        return implode(', ', array_map([$this, 'formatAddress'], $addresses));
    }

    /** RFC 2047 encode a header value when it isn't plain ASCII. */
    protected function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
