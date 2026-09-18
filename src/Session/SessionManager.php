<?php

namespace Nitro\Session;

use Closure;
use InvalidArgumentException;
use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Handlers\DatabaseSessionHandler;
use Nitro\Session\Handlers\FileSessionHandler;
use Nitro\Session\Handlers\RedisSessionHandler;
use RuntimeException;
use SessionHandlerInterface;

/**
 * Builds a {@see Store} for the configured (or requested) driver and memoizes
 * it. New drivers are added here — the rest of the framework depends only on
 * the Store / SessionInterface, never on a specific backend.
 *
 * Config keys: driver ('native'|'file'|'array'|'redis'|'database'), cookie,
 * lifetime (minutes), files (dir), connection (redis), table (database).
 */
class SessionManager
{
    /**
     * @param array         $config
     * @param Closure|null  $redis  Resolves a Redis connection by name. Injected
     *   rather than resolved here so the session layer never depends on the
     *   Redis layer being registered when another driver is in use.
     */
    public function __construct(
        private array $config,
        private ?Closure $redis = null,
    ) {}

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

    private function createHandler(string $name): SessionHandlerInterface
    {
        $lifetime = (int) ($this->config['lifetime'] ?? 120);

        return match ($name) {
            'array' => new ArraySessionHandler($lifetime),
            'file'  => new FileSessionHandler(
                $this->config['files'] ?? sys_get_temp_dir(),
                $lifetime,
            ),
            'redis' => new RedisSessionHandler(
                $this->redisConnection(),
                $lifetime,
                (string) ($this->config['prefix'] ?? 'nitro:session:'),
            ),
            'database' => new DatabaseSessionHandler(
                (string) ($this->config['table'] ?? 'sessions'),
                $lifetime,
            ),
            default => throw new InvalidArgumentException("Unsupported session driver [{$name}]."),
        };
    }

    /** The Redis connection the 'redis' driver writes to. */
    private function redisConnection(): object
    {
        if ($this->redis === null) {
            throw new RuntimeException(
                'The redis session driver needs a Redis connection resolver. '
                    . 'Register the Redis service provider, or choose another session driver.'
            );
        }

        return ($this->redis)($this->config['connection'] ?? null);
    }
}
