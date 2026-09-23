<?php

namespace Nitro\Auth\Passwords;

use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;

/**
 * Orchestrates the password-reset flow, mirroring Laravel's broker shape.
 *
 * It owns the policy (find the user, mint/validate the token, consume it) and
 * returns a status constant; the *side effects* — mailing the link, persisting
 * the new password — are supplied by the controller as callbacks, so the broker
 * stays free of mail/model concerns.
 */
class PasswordBroker
{
    public const RESET_LINK_SENT = 'passwords.sent';
    public const PASSWORD_RESET   = 'passwords.reset';
    public const INVALID_USER     = 'passwords.user';
    public const INVALID_TOKEN    = 'passwords.token';

    public function __construct(
        protected UserProvider $users,
        protected TokenRepository $tokens,
    ) {}

    /**
     * Find the user, mint a token, and hand (user, token) to $callback to send
     * the link. Returns RESET_LINK_SENT or INVALID_USER.
     */
    public function sendResetLink(array $credentials, callable $callback): string
    {
        $user = $this->users->retrieveByCredentials($credentials);

        if ($user === null) {
            return self::INVALID_USER;
        }

        $token = $this->tokens->create((string) $credentials['email']);

        $callback($user, $token);

        return self::RESET_LINK_SENT;
    }

    /**
     * Validate the token and, on success, hand (user, password) to $callback to
     * persist the new password, then consume the token. Returns PASSWORD_RESET,
     * INVALID_USER, or INVALID_TOKEN.
     */
    public function reset(array $credentials, callable $callback): string
    {
        $email = (string) ($credentials['email'] ?? '');
        $token = (string) ($credentials['token'] ?? '');

        $user = $this->users->retrieveByCredentials(['email' => $email]);

        if ($user === null) {
            return self::INVALID_USER;
        }

        if (!$this->tokens->exists($email, $token)) {
            return self::INVALID_TOKEN;
        }

        $callback($user, (string) ($credentials['password'] ?? ''));

        $this->tokens->delete($email);

        return self::PASSWORD_RESET;
    }

    /**
     * The user a set of credentials names.
     *
     * @throws \UnexpectedValueException When the user cannot reset a password.
     */
    public function getUser(array $credentials): ?Authenticatable
    {
        unset($credentials['token']);

        $user = $this->users->retrieveByCredentials($credentials);

        if ($user !== null && ! $user instanceof CanResetPassword) {
            throw new \UnexpectedValueException(
                'A user of type [' . $user::class . '] must implement CanResetPassword to reset one.'
            );
        }

        return $user;
    }

    /** Issue a reset token without sending anything. */
    public function createToken(Authenticatable $user): string
    {
        return $this->tokens->create($this->emailFor($user));
    }

    /** Drop whatever token a user has. */
    public function deleteToken(Authenticatable $user): void
    {
        $this->tokens->delete($this->emailFor($user));
    }

    /** Whether a token is the one this user was issued, and still current. */
    public function tokenExists(Authenticatable $user, string $token): bool
    {
        return $this->tokens->exists($this->emailFor($user), $token);
    }

    public function getRepository(): TokenRepository
    {
        return $this->tokens;
    }

    /** Where a reset link is sent, which is what a token is keyed by. */
    protected function emailFor(Authenticatable $user): string
    {
        if ($user instanceof CanResetPassword) {
            return $user->getEmailForPasswordReset();
        }

        return (string) ($user->email ?? '');
    }
}
