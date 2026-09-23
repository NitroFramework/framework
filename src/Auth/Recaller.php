<?php

namespace Nitro\Auth;

/**
 * Reads the three parts of a remember-me cookie.
 *
 * The identifier says who, the token proves the cookie was issued by
 * this application and has not been revoked, and the password hash
 * makes the cookie stop working the moment the password changes.
 */
class Recaller
{
    public function __construct(protected mixed $recaller)
    {
        // A cookie written by an older release may be serialized; an
        // unreadable one is left as it is and fails valid() below.
        if (is_string($recaller)) {
            $this->recaller = @unserialize($recaller, ['allowed_classes' => false]) ?: $recaller;
        }
    }

    public function id(): string
    {
        return $this->segments()[0] ?? '';
    }

    public function token(): string
    {
        return $this->segments()[1] ?? '';
    }

    /** The password hash the cookie was issued against. */
    public function hash(): string
    {
        return $this->segments()[2] ?? '';
    }

    public function valid(): bool
    {
        return $this->properString() && $this->hasAllSegments();
    }

    /** @return array<int, string> */
    public function segments(): array
    {
        return is_string($this->recaller) ? explode('|', $this->recaller) : [];
    }

    protected function properString(): bool
    {
        return is_string($this->recaller) && str_contains($this->recaller, '|');
    }

    protected function hasAllSegments(): bool
    {
        $segments = $this->segments();

        return count($segments) >= 3 && trim($segments[0]) !== '' && trim($segments[1]) !== '';
    }
}
