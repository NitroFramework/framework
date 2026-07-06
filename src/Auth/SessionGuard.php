<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Session\Contracts\SessionInterface;

/**
 * Session-based authentication guard: tracks who is logged in, handles
 * login/logout and the intended-URL round trip, and delegates user retrieval and
 * credential checking to a {@see UserProvider}. All state goes through the
 * injected {@see SessionInterface} rather than $_SESSION directly.
 */
class SessionGuard implements Guard
{
    /** Flat (non-dotted) session keys so the store never nests them. */
    protected const SESSION_KEY  = '_auth_id';
    protected const INTENDED_KEY = '_url_intended';

    /**
     * Dummy bcrypt hash used to equalise timing when no user matches the given
     * credentials, defeating user-enumeration via response-time differences.
     */
    protected const DUMMY_HASH = '$2y$12$cQ2P0z7gkq3uJ9w8nQnq2eY8wYh3yqgkq3uJ9w8nQnq2eY8wYh3y';

    protected ?Authenticatable $user = null;

    public function __construct(
        protected UserProvider $provider,
        protected SessionInterface $session,
    ) {}

    // ─── State queries ──────────────────────────────────────────────────────

    /**
     * The current authenticated user, lazily resolved from the session id.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->id();
        if ($id === null) {
            return null;
        }

        return $this->user = $this->provider->retrieveById($id);
    }

    /**
     * The current user's identifier (int or string), or null when a guest.
     */
    public function id(): int|string|null
    {
        return $this->session->get(self::SESSION_KEY);
    }

    /** Whether a user is authenticated. */
    public function check(): bool
    {
        return $this->id() !== null;
    }

    /** Whether the visitor is a guest (not authenticated). */
    public function guest(): bool
    {
        return !$this->check();
    }

    // ─── Authentication ───────────────────────────────────────────────────────

    /**
     * Validate credentials and, on success, log the user in. The non-password
     * keys are used to find the user; the password is verified in constant time.
     * Returns false on any failure without leaking which step failed.
     */
    public function attempt(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            // Burn a hash comparison so a missing user costs the same as a
            // wrong password — no timing oracle for enumeration.
            password_verify((string) ($credentials['password'] ?? ''), self::DUMMY_HASH);
            return false;
        }

        if (!$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        if ($this->provider instanceof EloquentUserProvider) {
            $this->provider->rehashPasswordIfRequired($user, $credentials);
        }

        $this->login($user);

        return true;
    }

    /**
     * Validate credentials WITHOUT logging anyone in.
     */
    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Verify a plaintext password against the currently authenticated user —
     * used by the confirm-password and change-password flows. False for guests.
     */
    public function validatePassword(string $password): bool
    {
        $user = $this->user();

        return $user !== null
            && $this->provider->validateCredentials($user, ['password' => $password]);
    }

    /**
     * Log a user in by storing their identifier in the session. Rotates the
     * session id first to prevent session fixation.
     */
    public function login(Authenticatable $user): void
    {
        $this->session->regenerate(true);
        $this->session->put(self::SESSION_KEY, $user->getAuthIdentifier());
        $this->user = $user;
    }

    /**
     * Look a user up by id and log them in. Returns the user, or null if the id
     * doesn't resolve.
     */
    public function loginUsingId(int|string $id): ?Authenticatable
    {
        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            return null;
        }

        $this->login($user);

        return $user;
    }

    /**
     * Log the current user out: drop the identifier and rotate the session id
     * so the old one can't be replayed.
     */
    public function logout(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->user = null;
        $this->session->regenerate(true);
    }

    // ─── Intended URL ─────────────────────────────────────────────────────────

    /** Remember where the user was headed before being redirected to login. */
    public function setIntendedUrl(string $url): void
    {
        $this->session->put(self::INTENDED_KEY, $url);
    }

    /** Get and forget the intended URL, falling back to $default. */
    public function getIntendedUrl(?string $default = null): ?string
    {
        $url = $this->session->get(self::INTENDED_KEY, $default);
        $this->session->forget(self::INTENDED_KEY);
        return $url;
    }
}
