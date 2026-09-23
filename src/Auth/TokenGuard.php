<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;

/**
 * Authenticates each request by an API token.
 *
 * Stateless by nature: the credential arrives with every request, so
 * there is no session to establish and nothing to sign out of.
 *
 * The token is looked for in a query parameter, then the body, then a
 * bearer header — the header being what a client should use, and the
 * others what a webhook or a form is often limited to.
 */
class TokenGuard implements Guard
{
    use GuardHelpers;

    /**
     * @param string $inputKey   The query or body key the token arrives under.
     * @param string $storageKey The column it is stored in.
     * @param bool   $hash       Whether the stored token is a sha256 digest.
     */
    public function __construct(
        UserProvider $provider,
        protected mixed $request = null,
        protected string $inputKey = 'api_token',
        protected string $storageKey = 'api_token',
        protected bool $hash = false,
    ) {
        $this->provider = $provider;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->getTokenForRequest();

        if ($token === null || $token === '') {
            return null;
        }

        return $this->user = $this->provider->retrieveByCredentials([
            $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
        ]);
    }

    /** The token this request carries, wherever it put it. */
    public function getTokenForRequest(): ?string
    {
        $request = $this->request();

        if ($request === null) {
            return null;
        }

        $key = $this->inputKey;

        foreach ([
            static fn () => $request->query($key),
            static fn () => $request->input($key),
            static fn () => $request->bearerToken(),
        ] as $source) {
            try {
                $token = $source();
            } catch (\Throwable) {
                continue;
            }

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Whether a token names a user.
     *
     * The credential is the token, so there is nothing else to check.
     */
    public function validate(array $credentials): bool
    {
        $token = $credentials[$this->inputKey] ?? null;

        if (! is_string($token) || $token === '') {
            return false;
        }

        return $this->provider->retrieveByCredentials([
            $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
        ]) !== null;
    }

    public function setRequest(mixed $request): static
    {
        $this->request = $request;

        return $this;
    }

    protected function request(): mixed
    {
        if ($this->request !== null) {
            return $this->request;
        }

        try {
            return $this->request = \app('request');
        } catch (\Throwable) {
            return null;
        }
    }
}
