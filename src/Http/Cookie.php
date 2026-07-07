<?php

namespace Nitro\Http;

/**
 * An outgoing cookie. Immutable; toHeader() renders the Set-Cookie value.
 */
class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $expiresAt = 0,      // unix timestamp; 0 = session cookie
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
        public readonly bool $raw = false,
    ) {}

    /** Return a copy with a different value (used to swap plaintext for ciphertext). */
    public function withValue(string $value): self
    {
        return new self(
            $this->name, $value, $this->expiresAt, $this->path, $this->domain,
            $this->secure, $this->httpOnly, $this->sameSite, $this->raw,
        );
    }

    /** Whether this cookie deletes the client value (past expiry / empty value). */
    public function isCleared(): bool
    {
        return $this->value === '' && $this->expiresAt !== 0 && $this->expiresAt < time();
    }

    public function toHeader(): string
    {
        $encode = $this->raw ? static fn (string $v): string => $v : 'rawurlencode';

        $parts = [$encode($this->name) . '=' . $encode($this->value)];
        $parts[] = 'Path=' . $this->path;

        if ($this->expiresAt !== 0) {
            $parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s \G\M\T', $this->expiresAt);
            $parts[] = 'Max-Age=' . max(0, $this->expiresAt - time());
        }

        if ($this->domain !== null && $this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite !== '') {
            $parts[] = 'SameSite=' . ucfirst(strtolower($this->sameSite));
        }

        return implode('; ', $parts);
    }
}
