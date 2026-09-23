<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user built from a row rather than a model.
 *
 * What the database provider hands back: enough to authenticate and no
 * more, for an application whose users are not an Eloquent model.
 */
class GenericUser implements Authenticatable
{
    /** @param array<string, mixed> $attributes */
    public function __construct(protected array $attributes = []) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes[$this->getAuthIdentifierName()] ?? null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->attributes[$this->getAuthPasswordName()] ?? '');
    }

    public function getRememberToken(): ?string
    {
        $token = $this->attributes[$this->getRememberTokenName()] ?? null;

        return $token === null ? null : (string) $token;
    }

    public function setRememberToken(?string $token): void
    {
        $this->attributes[$this->getRememberTokenName()] = $token;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}
