<?php

namespace Nitro\Auth;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\StatefulGuard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Session\Contracts\Session;
use Nitro\Support\Str;

/**
 * Signs a user in against a session, and remembers them if asked.
 *
 * The session holds the identifier for as long as the browser keeps the
 * session cookie. A remember-me cookie outlives it: it carries the
 * identifier, a revocable token and the password hash, so it stops
 * working the moment either is changed.
 */
class SessionGuard implements StatefulGuard
{
    use GuardHelpers;

    protected const SESSION_KEY  = '_auth_id';
    protected const INTENDED_KEY = '_url_intended';

    /**
     * A hash to compare against when no user was found.
     *
     * Burning the same work keeps a missing account from being faster
     * than a wrong password, which would otherwise enumerate users.
     */
    protected static ?string $dummyHash = null;

    protected static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash('nitro-timing-equaliser', PASSWORD_DEFAULT);
    }

    /** Whether the user was resolved from a remember-me cookie. */
    protected bool $viaRemember = false;

    /** Whether the cookie has already been tried this request. */
    protected bool $recallAttempted = false;

    /** The last user credentials were checked against. */
    protected ?Authenticatable $lastAttempted = null;

    /** Set when a session should not be written, for a one-off check. */
    protected bool $stateless = false;

    /** Days a remember-me cookie lasts; null keeps it for five years. */
    protected ?int $rememberDuration = null;

    public function __construct(
        protected UserProvider $provider,
        protected Session $session,
        protected ?Dispatcher $events = null,
        protected string $name = 'web',
    ) {}

    // ─── State queries ──────────────────────────────────────────────────────

    /**
     * The signed-in user, from the session or a remember-me cookie.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->id();

        if ($id !== null && ($user = $this->provider->retrieveById($id)) !== null) {
            $this->event(new Events\Authenticated($user, $this->name));

            return $this->user = $user;
        }

        $user = $this->userFromRecaller($this->recaller());

        if ($user !== null) {
            $this->updateSession($user->getAuthIdentifier());

            $this->event(new Events\Login($user, $this->name, true));
            $this->event(new Events\Authenticated($user, $this->name));
        }

        return $this->user = $user;
    }

    public function id(): int|string|null
    {
        return $this->session->get(self::SESSION_KEY);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    /** Whether this request was authenticated by a remember-me cookie. */
    public function viaRemember(): bool
    {
        return $this->viaRemember;
    }

    /** The last user credentials were checked against, successfully or not. */
    public function getLastAttempted(): ?Authenticatable
    {
        return $this->lastAttempted;
    }

    /** Set the user without touching the session. */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->viaRemember = false;

        $this->event(new Events\Authenticated($user, $this->name));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ─── Authentication ───────────────────────────────────────────────────────

    /**
     * Check credentials and sign the user in if they hold.
     *
     * @param bool $remember Issue a cookie that outlives the session.
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $this->event(new Events\Attempting($credentials, $this->name, $remember));

        $user = $this->lastAttempted = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            // Burn a hash comparison so a missing user costs the same as a
            // wrong password — no timing oracle for enumeration.
            password_verify((string) ($credentials['password'] ?? ''), self::dummyHash());

            $this->event(new Events\Failed($credentials, null, $this->name));

            return false;
        }

        if (! $this->provider->validateCredentials($user, $credentials)) {
            $this->event(new Events\Failed($credentials, $user, $this->name));

            return false;
        }

        $this->event(new Events\Validated($user, $this->name));

        if ($this->provider instanceof EloquentUserProvider) {
            $this->provider->rehashPasswordIfRequired($user, $credentials);
        }

        $this->login($user, $remember);

        return true;
    }

    /**
     * Check credentials and set the user for this request only.
     *
     * No session is written, which is what an API request authenticating
     * per call wants — a session would be a cookie nobody reads.
     */
    public function once(array $credentials): bool
    {
        $this->event(new Events\Attempting($credentials, $this->name));

        $user = $this->lastAttempted = $this->provider->retrieveByCredentials($credentials);

        if ($user === null || ! $this->provider->validateCredentials($user, $credentials)) {
            $this->event(new Events\Failed($credentials, $user, $this->name));

            return false;
        }

        $this->setUser($user);

        return true;
    }

    /** Set a user for this request only, by identifier. */
    public function onceUsingId(int|string $id): ?Authenticatable
    {
        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            return null;
        }

        $this->setUser($user);

        return $user;
    }

    /** Whether credentials hold, without signing anybody in. */
    public function validate(array $credentials): bool
    {
        $user = $this->lastAttempted = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    /** Whether a password is the signed-in user's. */
    public function validatePassword(string $password): bool
    {
        $user = $this->user();

        return $user !== null
            && $this->provider->validateCredentials($user, ['password' => $password]);
    }

    /**
     * Sign a user in.
     *
     * @param bool $remember Issue a cookie that outlives the session.
     */
    public function login(Authenticatable $user, bool $remember = false): void
    {
        $this->updateSession($user->getAuthIdentifier());

        if ($remember) {
            $this->ensureRememberTokenIsSet($user);
            $this->queueRecallerCookie($user);
        }

        $this->user = $user;

        $this->event(new Events\Login($user, $this->name, $remember));
    }

    public function loginUsingId(int|string $id, bool $remember = false): ?Authenticatable
    {
        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            return null;
        }

        $this->login($user, $remember);

        return $user;
    }

    /**
     * Sign the user out, here and on every remembered device.
     *
     * The remember token is cycled, which is what revokes a cookie held
     * on a device this session cannot reach.
     */
    public function logout(): void
    {
        $user = $this->user();

        $this->session->forget(self::SESSION_KEY);
        $this->session->regenerate(true);

        if ($user !== null) {
            $this->cycleRememberToken($user);
            $this->forgetRecallerCookie();

            $this->event(new Events\Logout($user, $this->name));
        }

        $this->user = null;
        $this->viaRemember = false;
        $this->recallAttempted = false;
    }

    /**
     * Sign out of this device, leaving the others signed in.
     *
     * The token is left alone, so a cookie on another device still
     * works — which is the difference from logout().
     */
    public function logoutCurrentDevice(): void
    {
        $user = $this->user();

        $this->session->forget(self::SESSION_KEY);
        $this->session->regenerate(true);

        if ($user !== null) {
            $this->event(new Events\CurrentDeviceLogout($user, $this->name));
        }

        $this->user = null;
        $this->viaRemember = false;
        $this->recallAttempted = false;
    }

    /**
     * Sign out of every other device, staying signed in here.
     *
     * Cycling the token invalidates every remember-me cookie, including
     * this one, so a fresh cookie is issued for this session. The
     * password is asked for because this is a security action and the
     * session alone may not be the account's owner.
     */
    public function logoutOtherDevices(string $password): ?Authenticatable
    {
        $user = $this->user();

        if ($user === null || ! $this->provider->validateCredentials($user, ['password' => $password])) {
            return null;
        }

        $this->cycleRememberToken($user);

        if ($this->recaller() !== null) {
            $this->queueRecallerCookie($user);
        }

        $this->event(new Events\OtherDeviceLogout($user, $this->name));

        return $user;
    }

    // ─── Remember me ──────────────────────────────────────────────────────────

    /** Days a remember-me cookie lasts. */
    public function setRememberDuration(int $days): static
    {
        $this->rememberDuration = $days;

        return $this;
    }

    public function getRecallerName(): string
    {
        return 'remember_' . $this->name . '_' . sha1(static::class);
    }

    /**
     * The user a remember-me cookie names, when it is still good for one.
     *
     * Three things have to hold: the cookie parses, the token still
     * matches the stored one, and the password hash it was issued
     * against is still current. The last is what makes changing a
     * password sign out every remembered device.
     */
    protected function userFromRecaller(?Recaller $recaller): ?Authenticatable
    {
        if ($recaller === null || ! $recaller->valid() || $this->recallAttempted) {
            return null;
        }

        $this->recallAttempted = true;

        $user = $this->provider->retrieveByToken($recaller->id(), $recaller->token());

        if ($user === null) {
            return null;
        }

        $password = $user->getAuthPassword();

        if ($password === '' || ! hash_equals($this->hashPasswordForCookie($password), $recaller->hash())) {
            return null;
        }

        $this->viaRemember = true;

        return $user;
    }

    /** The cookie this request arrived with, when there is one. */
    protected function recaller(): ?Recaller
    {
        $value = $this->readCookie($this->getRecallerName());

        return $value === null ? null : new Recaller($value);
    }

    /**
     * What goes in the cookie in place of the password hash.
     *
     * The hash itself would be a stored credential travelling to the
     * browser; this is enough to notice it changing and no use if the
     * cookie is stolen.
     */
    public function hashPasswordForCookie(string $password): string
    {
        return hash('sha256', $password);
    }

    protected function ensureRememberTokenIsSet(Authenticatable $user): void
    {
        if (($user->getRememberToken() ?? '') === '') {
            $this->cycleRememberToken($user);
        }
    }

    protected function cycleRememberToken(Authenticatable $user): void
    {
        $this->provider->updateRememberToken($user, Str::random(60));
    }

    protected function queueRecallerCookie(Authenticatable $user): void
    {
        $this->writeCookie(
            $this->getRecallerName(),
            $user->getAuthIdentifier() . '|'
                . $user->getRememberToken() . '|'
                . $this->hashPasswordForCookie($user->getAuthPassword()),
            ($this->rememberDuration ?? (5 * 365)) * 24 * 60,
        );
    }

    protected function forgetRecallerCookie(): void
    {
        $this->writeCookie($this->getRecallerName(), '', -2628000);
    }

    /**
     * These three reach the cookie jar when the application has one.
     *
     * A guard built against a session alone — a console command, a test
     * — simply keeps no cookie, and remember-me is then inert rather
     * than an error.
     */
    protected function readCookie(string $name): ?string
    {
        try {
            $request = \app('request');
        } catch (\Throwable) {
            return null;
        }

        $value = $request->cookie($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function writeCookie(string $name, string $value, int $minutes): void
    {
        try {
            $cookies = \app('cookie');
        } catch (\Throwable) {
            return;
        }

        $cookies->queue($cookies->make($name, $value, $minutes, httpOnly: true));
    }

    // ─── Session ──────────────────────────────────────────────────────────────

    /**
     * Record the identifier, on a session id that has not been used before.
     *
     * Regenerating is what stops a session fixed before the sign-in from
     * being a session belonging to the user after it.
     */
    protected function updateSession(mixed $id): void
    {
        $this->session->regenerate(true);
        $this->session->put(self::SESSION_KEY, $id);
    }

    // ─── Intended URL ─────────────────────────────────────────────────────────

    public function setIntendedUrl(string $url): void
    {
        $this->session->put(self::INTENDED_KEY, $url);
    }

    public function getIntendedUrl(?string $default = null): ?string
    {
        $url = $this->session->get(self::INTENDED_KEY, $default);
        $this->session->forget(self::INTENDED_KEY);

        return $url;
    }

    // ─── Events ───────────────────────────────────────────────────────────────

    public function setDispatcher(?Dispatcher $events): static
    {
        $this->events = $events;

        return $this;
    }

    public function getDispatcher(): ?Dispatcher
    {
        return $this->events;
    }

    protected function event(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
