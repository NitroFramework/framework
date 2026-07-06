<?php

namespace Nitro\Auth\Concerns;

/**
 * Default {@see \Nitro\Auth\Contracts\Authenticatable} implementation for models.
 *
 * Reads identity off the model's primary key and the password off the
 * configurable password column (default "password"), so a model becomes
 * authenticatable by adding `use Authenticatable;` and implementing the
 * contract — no per-model boilerplate. Assumes a host with getKey()/getKeyName()
 * /getAttribute() (i.e. a BaseModel descendant).
 */
trait Authenticatable
{
    /**
     * Name of the unique identifier column — the model's primary key name.
     */
    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    /**
     * The unique identifier value — the model's primary key.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * The hashed password from the configured password column.
     */
    public function getAuthPassword(): string
    {
        return (string) ($this->getAttribute($this->getAuthPasswordName()) ?? '');
    }

    /**
     * Column holding the password hash. Override in a model to use a different
     * column name.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }
}
