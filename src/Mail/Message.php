<?php

namespace Nitro\Mail;

/**
 * A mail message: recipients, sender, subject, HTML and/or text body, and
 * attachments. Fluent builder used by the Mailer and transports.
 */
class Message
{
    /** @var array<int, array{address: string, name: ?string}> */
    public array $to = [];
    /** @var array<int, array{address: string, name: ?string}> */
    public array $cc = [];
    /** @var array<int, array{address: string, name: ?string}> */
    public array $bcc = [];

    /** @var array{address: string, name: ?string}|null */
    public ?array $from = null;

    /**
     * Where a reply goes, which may be more than one address.
     *
     * @var array<int, array{address: string, name: ?string}>
     */
    public array $replyTo = [];

    public string $subject = '';
    public ?string $html = null;
    public ?string $text = null;

    /** @var array<int, array{name: string, mime: string, content: string}> */
    public array $attachments = [];

    /**
     * Custom headers, name => value.
     *
     * What a delivery log and a provider's bounce webhook use to recognise a
     * message days after it was sent.
     *
     * @var array<string, string>
     */
    public array $headers = [];

    public function from(string $address, ?string $name = null): static
    {
        $this->from = ['address' => $this->assertValidAddress($address), 'name' => $name];
        return $this;
    }

    public function to(string $address, ?string $name = null): static
    {
        $this->to[] = ['address' => $this->assertValidAddress($address), 'name' => $name];
        return $this;
    }

    public function cc(string $address, ?string $name = null): static
    {
        $this->cc[] = ['address' => $this->assertValidAddress($address), 'name' => $name];
        return $this;
    }

    public function bcc(string $address, ?string $name = null): static
    {
        $this->bcc[] = ['address' => $this->assertValidAddress($address), 'name' => $name];
        return $this;
    }

    public function replyTo(string $address, ?string $name = null): static
    {
        $this->replyTo[] = ['address' => $this->assertValidAddress($address), 'name' => $name];
        return $this;
    }

    /**
     * Validate an email address before it can reach a mail header. Rejects
     * anything that isn't a valid address — which crucially rejects embedded
     * CR/LF, closing off SMTP header injection (a user-supplied address like
     * "x@x.dev\r\nBcc: victim@evil.com" could otherwise inject headers, since
     * the transport writes the address into To/From/Cc verbatim).
     *
     * @throws \InvalidArgumentException on an invalid address.
     */
    private function assertValidAddress(string $address): string
    {
        $address = trim($address);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Invalid email address: [{$address}].");
        }

        return $address;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set a custom header, or read one back when the value is omitted.
     *
     *     $message->header('X-App-Template', 'certificate.issued');
     *
     * What this is for is tying a message that has left the building back to
     * the thing that produced it: a delivery log answering "did this learner
     * get their certificate email?", or a provider's bounce webhook arriving
     * days later with nothing but the message's own headers to identify it by.
     *
     * A name or value carrying a line break would let anything user-supplied
     * append headers of its own — a Bcc to somewhere else being the obvious one
     * — so both are refused outright rather than stripped. Silently removing
     * the break would keep a forged value in the header.
     *
     * @throws \InvalidArgumentException when either part could break the header block.
     */
    public function header(string $name, ?string $value = null): static|string|null
    {
        if ($value === null) {
            return $this->headers[$name] ?? null;
        }

        $this->headers[$this->assertHeaderSafe($name, 'name')] = $this->assertHeaderSafe($value, 'value');

        return $this;
    }

    /**
     * Set several headers at once.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }

        return $this;
    }

    private function assertHeaderSafe(string $part, string $what): string
    {
        if (preg_match('/[\r\n\0]/', $part) === 1 || trim($part) === '') {
            throw new \InvalidArgumentException("Invalid header {$what}: [{$part}].");
        }

        return trim($part);
    }

    public function html(string $html): static
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): static
    {
        $this->text = $text;
        return $this;
    }

    /** Attach a file from disk. */
    public function attach(string $path, ?string $name = null, ?string $mime = null): static
    {
        return $this->attachData(
            (string) file_get_contents($path),
            $name ?? basename($path),
            $mime ?? (function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream'),
        );
    }

    /** Attach raw in-memory data. */
    public function attachData(string $data, string $name, ?string $mime = null): static
    {
        $this->attachments[] = ['name' => $name, 'mime' => $mime ?? 'application/octet-stream', 'content' => $data];
        return $this;
    }

    /** All recipient addresses (to + cc + bcc) for the SMTP envelope. */
    public function recipients(): array
    {
        return array_map(
            static fn (array $recipient): string => $recipient['address'],
            array_merge($this->to, $this->cc, $this->bcc),
        );
    }
}
