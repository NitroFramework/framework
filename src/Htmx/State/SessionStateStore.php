<?php

namespace Nitro\Htmx\State;

/**
 * Default backend — writes through to $_SESSION. Auto-scoped per user
 * by PHP's session machinery, so this store doesn't need to manage
 * isolation itself.
 *
 * Starts the session lazily if one isn't already running, but only
 * when headers haven't been sent yet (so test harnesses that alias
 * $_SESSION manually still work).
 */
class SessionStateStore implements StateStore
{
    public function get(string $key): ?array
    {
        $this->bootSession();
        $value = $_SESSION[$key] ?? null;
        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, ?int $ttl = null): void
    {
        $this->bootSession();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->bootSession();
        unset($_SESSION[$key]);
    }

    private function bootSession(): void
    {
        // Skip if a harness has already aliased $_SESSION into a private
        // store; only start a real PHP session when one is truly absent
        // and we still have a chance to set the cookie.
        if (!isset($_SESSION) && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}
