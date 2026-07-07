<?php

namespace Nitro\Htmx\State;

/**
 * Default backend — persists HTMX component state through the framework session
 * Store (the single session seam), so it works under whatever backend is active
 * (native/file/redis) in worker mode or not. Auto-scoped per user by the
 * session itself. Falls back to raw $_SESSION only when no session service is
 * bound (CLI/bootstrap, or a test harness that aliases $_SESSION).
 */
class SessionStateStore implements StateStore
{
    public function get(string $key): ?array
    {
        $session = $this->session();
        $value = $session !== null
            ? $session->get($key)
            : ($this->bootSession() ? ($_SESSION[$key] ?? null) : null);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, ?int $ttl = null): void
    {
        $session = $this->session();
        if ($session !== null) {
            $session->put($key, $value);
            return;
        }
        $this->bootSession();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $session = $this->session();
        if ($session !== null) {
            $session->forget($key);
            return;
        }
        $this->bootSession();
        unset($_SESSION[$key]);
    }

    /**
     * The bound session Store via the canonical nitro_session() seam, or null
     * when none is available (CLI/bootstrap). Never touches $_SESSION when a
     * Store exists.
     *
     * @return \Nitro\Session\Contracts\SessionInterface|null
     */
    private function session()
    {
        try {
            return nitro_session();
        } catch (\Throwable) {
            return null;
        }
    }

    private function bootSession(): bool
    {
        // Only used on the $_SESSION fallback path (no framework session bound).
        if (!isset($_SESSION) && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        return isset($_SESSION);
    }
}
