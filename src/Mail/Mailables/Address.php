<?php

namespace Nitro\Mail\Mailables;

use InvalidArgumentException;

/**
 * One email address, with an optional display name.
 */
class Address
{
    /**
     * @throws InvalidArgumentException When the address contains a line break.
     */
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {
        // A line break in an address is a header injection, not a typo.
        if (preg_match('/[\r\n]/', $address) > 0) {
            throw new InvalidArgumentException('Email addresses may not contain line break characters.');
        }
    }

    /** The address as a mail header writes it. */
    public function toString(): string
    {
        return $this->name === null || $this->name === ''
            ? $this->address
            : sprintf('%s <%s>', $this->name, $this->address);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
