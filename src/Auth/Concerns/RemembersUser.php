<?php

namespace Nitro\Auth\Concerns;

/**
 * The remember-me half of Authenticatable, kept in memory.
 *
 * For something that satisfies the contract without being a model —
 * an object standing in for a user, or one whose credentials come from
 * somewhere other than a database. A model uses {@see Authenticatable}
 * instead, which reads and writes the token as an attribute.
 */
trait RemembersUser
{
    protected ?string $rememberToken = null;

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken(?string $token): void
    {
        $this->rememberToken = $token;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
