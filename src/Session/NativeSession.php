<?php

namespace Nitro\Session;

/**
 * A {@see Store} backed by PHP's native session.
 *
 * The attribute bag is bound by reference to $_SESSION, so code reaching for
 * the superglobal and code using the Store see one session rather than two.
 * PHP owns persistence and the session id, so the parent's handler is unused.
 *
 * Not worker-safe: ext/session keeps process globals a worker never tears
 * down. Use the file driver where the process is long-lived.
 */
class NativeSession extends Store
{
    public function __construct(string $name, ?string $id = null)
    {
        // The handler is required by the parent type but never used: native
        // sessions are persisted by PHP, not through the handler.
        parent::__construct($name, new ArraySessionHandler(), $id);
    }

    /**
     * Start the native session and bind the attribute bag to $_SESSION so all
     * reads/writes hit the live superglobal.
     */
    public function start(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                // Output already started (CLI/edge cases): fall back to a
                // detached array so the app keeps working without a warning.
                $_SESSION ??= [];
            } else {
                session_name($this->getName());
                session_start();
            }
        }

        $this->attributes = &$_SESSION;

        if (!$this->has('_csrf')) {
            $this->regenerateToken();
        }

        return $this->started = true;
    }

    /**
     * Age flash data (so flashed values survive exactly one further request),
     * then close the native session. Closing early releases the session file
     * lock so concurrent requests from the same client aren't serialised.
     */
    public function save(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->ageFlashData();
            session_write_close();
        }

        // Clear the process-global $_SESSION so a persistent FrankenPHP worker
        // can't leak this request's session into the next one (session_start()
        // for a different id would otherwise start from stale in-memory data).
        $_SESSION = [];
        $this->started = false;
    }

    /** PHP owns the id; report whatever the native session currently uses. */
    public function getId(): string
    {
        $id = session_id();
        return $id === false ? '' : $id;
    }

    /**
     * The native id is driven by the request cookie; only honour an explicit id
     * before the session starts. Called by the parent constructor with null,
     * which is a no-op here.
     */
    public function setId(?string $id): void
    {
        if (is_string($id) && $id !== '' && session_status() === PHP_SESSION_NONE) {
            session_id($id);
        }
    }

    /** Rotate the native session id, optionally destroying the old store entry. */
    public function migrate(bool $destroy = false): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($destroy);
        }
        return false;
    }

    /**
     * Empty the session in place. Assigning to $_SESSION (rather than rebinding
     * the property) keeps the by-reference link intact.
     */
    public function flush(): void
    {
        $_SESSION = [];
    }
}
