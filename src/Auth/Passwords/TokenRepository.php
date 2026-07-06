<?php

namespace Nitro\Auth\Passwords;

use Nitro\Database\DB;

/**
 * Database-backed store for password-reset tokens.
 *
 * The token is hashed before storage (like a password) so a leaked
 * password_reset_tokens table can't be used to reset accounts — only the
 * plaintext token mailed to the user validates. One active token per email;
 * creating a new one replaces the old.
 */
class TokenRepository
{
    public function __construct(
        protected string $table = 'password_reset_tokens',
        protected int $expires = 3600,
    ) {}

    /**
     * Issue a fresh token for the email, replacing any existing one. Returns the
     * plaintext token (to embed in the reset link); only its hash is stored.
     */
    public function create(string $email): string
    {
        $this->delete($email);

        $token = bin2hex(random_bytes(32));

        DB::table($this->table)->insert([
            'email'      => $email,
            'token'      => password_hash($token, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Whether the given plaintext token is valid (matches and not expired).
     * Expired tokens are pruned on access.
     */
    public function exists(string $email, string $token): bool
    {
        $record = DB::table($this->table)->where('email', $email)->first();

        if ($record === null) {
            return false;
        }

        if ($this->expired((string) $record->created_at)) {
            $this->delete($email);
            return false;
        }

        return password_verify($token, (string) $record->token);
    }

    /**
     * Remove any token for the email.
     */
    public function delete(string $email): void
    {
        DB::table($this->table)->where('email', $email)->delete();
    }

    /**
     * Whether a token's creation timestamp is older than the expiry window.
     */
    protected function expired(string $createdAt): bool
    {
        return strtotime($createdAt) + $this->expires < time();
    }
}
