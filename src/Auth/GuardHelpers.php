<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Exceptions\AuthenticationException;

/**
 * The parts of a guard that follow from user().
 *
 * Every guard answers these the same way once it can say who the user
 * is, so only that one method is left to each of them.
 */
trait GuardHelpers
{
    protected ?Authenticatable $user = null;

    protected UserProvider $provider;

    /**
     * The user, or an exception if there is none.
     *
     * @throws AuthenticationException
     */
    public function authenticate(): Authenticatable
    {
        return $this->user() ?? throw new AuthenticationException();
    }

    /** Whether a user has already been resolved this request. */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        $id = $this->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    /** Drop the resolved user, so the next call looks again. */
    public function forgetUser(): static
    {
        $this->user = null;

        return $this;
    }

    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    public function setProvider(UserProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }
}
