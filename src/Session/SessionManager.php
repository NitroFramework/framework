<?php

namespace Nitro\Session;

use InvalidArgumentException;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Handlers\FileSessionHandler;
use Nitro\Session\NativeSession;

/**
 * Builds a {@see Store} for the configured (or requested) driver and memoizes
 * it. New drivers are added here — the rest of the framework depends only on
 * the Store / SessionInterface, never on a specific backend.
 *
 * Config keys: driver ('file'|'array'), cookie, lifetime (minutes), files (dir).
 */
class SessionManager
{
    public function __construct(private array $config) {}

    /**
     * Build a Store for a driver (defaults to the configured one).
     *
     * Deliberately NOT memoized: the canonical 'session' binding is scoped()
     * in the container, which provides per-request caching AND flushes between
     * worker requests. Memoizing here would defeat that — a stale Store would
     * survive forgetScopedInstances() and leak one request's data into the next.
     */
    public function driver(?string $name = null): Store
    {
        return $this->createStore($name ?? $this->config['driver'] ?? 'file');
    }

    private function createStore(string $name): Store
    {
        $cookie = $this->config['cookie'] ?? 'nitro_session';

        // "native" is backed by PHP's own $_SESSION (see NativeSession) and has
        // no pluggable handler — it's the default while the app/HTMX layer still
        // reads the superglobal directly.
        if ($name === 'native') {
            return new NativeSession($cookie);
        }

        return new Store($cookie, $this->createHandler($name));
    }

    private function createHandler(string $name): \SessionHandlerInterface
    {
        $lifetime = (int) ($this->config['lifetime'] ?? 120);

        return match ($name) {
            'array' => new ArraySessionHandler($lifetime),
            'file'  => new FileSessionHandler(
                $this->config['files'] ?? sys_get_temp_dir(),
                $lifetime,
            ),
            default => throw new InvalidArgumentException("Unsupported session driver [{$name}]."),
        };
    }
}
