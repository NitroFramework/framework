<?php

namespace Nitro\Auth;

use Closure;
use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;

/**
 * Authenticates by whatever callback you give it.
 *
 *     Auth::viaRequest('headers', fn ($request) =>
 *         User::where('api_key', $request->header('X-Api-Key'))->first()
 *     );
 *
 * For a scheme the framework does not model — a signed header, a
 * mutual-TLS certificate, an identity another service vouched for.
 */
class RequestGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        protected Closure $callback,
        protected mixed $request = null,
        ?UserProvider $provider = null,
    ) {
        if ($provider !== null) {
            $this->provider = $provider;
        }
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $user = ($this->callback)($this->request(), $this->provider ?? null);

        return $this->user = $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Whether credentials name a user.
     *
     * The callback reads a request, not credentials, so this asks it
     * against a request carrying them.
     */
    public function validate(array $credentials): bool
    {
        $user = ($this->callback)((object) $credentials, $this->provider ?? null);

        return $user instanceof Authenticatable;
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
