<?php

namespace Nitro\Mail\Mailables;

use Closure;
use Nitro\Support\Arr;

/**
 * Who a message is from and to, and what it is called.
 *
 * Everything on the outside of the envelope, separate from what is
 * inside it — a mailable declares one in envelope().
 */
class Envelope
{
    public ?Address $from;

    /** @var array<int, Address> */
    public array $to;

    /** @var array<int, Address> */
    public array $cc;

    /** @var array<int, Address> */
    public array $bcc;

    /** @var array<int, Address> */
    public array $replyTo;

    /** @var array<int, Closure> Run against the built message. */
    public array $using;

    /**
     * @param array<int, string>      $tags
     * @param array<string, string|int> $metadata
     * @param Closure|array<int, Closure> $using
     */
    public function __construct(
        Address|string|null $from = null,
        Address|array|string $to = [],
        Address|array|string $cc = [],
        Address|array|string $bcc = [],
        Address|array|string $replyTo = [],
        public ?string $subject = null,
        public array $tags = [],
        public array $metadata = [],
        Closure|array $using = [],
    ) {
        $this->from = is_string($from) ? new Address($from) : $from;
        $this->to = $this->normalizeAddresses($to);
        $this->cc = $this->normalizeAddresses($cc);
        $this->bcc = $this->normalizeAddresses($bcc);
        $this->replyTo = $this->normalizeAddresses($replyTo);
        $this->using = Arr::wrap($using);
    }

    /** @return array<int, Address> */
    protected function normalizeAddresses(Address|array|string $addresses): array
    {
        return array_values(array_map(
            static fn (Address|string $address): Address => is_string($address)
                ? new Address($address)
                : $address,
            Arr::wrap($addresses),
        ));
    }

    // ── Building ──────────────────────────────────────────────────────

    public function from(Address|string $address, ?string $name = null): static
    {
        $this->from = is_string($address) ? new Address($address, $name) : $address;

        return $this;
    }

    public function to(Address|array|string $address, ?string $name = null): static
    {
        $this->to = array_merge($this->to, $this->addressesFrom($address, $name));

        return $this;
    }

    public function cc(Address|array|string $address, ?string $name = null): static
    {
        $this->cc = array_merge($this->cc, $this->addressesFrom($address, $name));

        return $this;
    }

    public function bcc(Address|array|string $address, ?string $name = null): static
    {
        $this->bcc = array_merge($this->bcc, $this->addressesFrom($address, $name));

        return $this;
    }

    public function replyTo(Address|array|string $address, ?string $name = null): static
    {
        $this->replyTo = array_merge($this->replyTo, $this->addressesFrom($address, $name));

        return $this;
    }

    /**
     * Read one argument pair as a list of addresses.
     *
     * A name given alongside a string address names that one address,
     * rather than being one of a list.
     *
     * @return array<int, Address>
     */
    private function addressesFrom(Address|array|string $address, ?string $name): array
    {
        return $this->normalizeAddresses(
            is_string($name) && is_string($address) ? [new Address($address, $name)] : $address
        );
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /** @param array<int, string> $tags */
    public function tags(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    public function tag(string $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function metadata(string $key, string|int $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /** Run a callback against the built message, for anything unmodelled. */
    public function using(Closure $callback): static
    {
        $this->using[] = $callback;

        return $this;
    }

    // ── Asking ────────────────────────────────────────────────────────

    public function isFrom(string $address, ?string $name = null): bool
    {
        if ($this->from === null) {
            return false;
        }

        return $this->from->address === $address
            && ($name === null || $this->from->name === $name);
    }

    public function hasTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->to, $address, $name);
    }

    public function hasCc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->cc, $address, $name);
    }

    public function hasBcc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->bcc, $address, $name);
    }

    public function hasReplyTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->replyTo, $address, $name);
    }

    public function hasSubject(string $subject): bool
    {
        return $this->subject === $subject;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function hasMetadata(string $key, string|int $value): bool
    {
        return ($this->metadata[$key] ?? null) === $value;
    }

    /** @param array<int, Address> $recipients */
    private function hasRecipient(array $recipients, string $address, ?string $name = null): bool
    {
        foreach ($recipients as $recipient) {
            if ($recipient->address !== $address) {
                continue;
            }

            if ($name === null || $recipient->name === $name) {
                return true;
            }
        }

        return false;
    }
}
