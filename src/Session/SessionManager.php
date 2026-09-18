<?php

namespace Nitro\Session;

use Closure;
use InvalidArgumentException;
use Nitro\Cache\Repository;
use Nitro\Cookie\CookieJar;
use Nitro\Encryption\Contracts\Encrypter;
use RuntimeException;
use SessionHandlerInterface;

/**
 * Builds a {@see Store} for the configured (or requested) driver.
 *
 * New drivers are added here — the rest of the framework depends only on the
 * Store / Session contract, never on a specific backend.
 *
 * Config keys: driver ('native'|'file'|'array'|'cookie'|'cache'|'redis'|
 * 'database'|'null'), cookie, lifetime (minutes), encrypt, serialization,
 * files (dir), connection (redis), store (cache), table (database).
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
        private ?Closure $cookie = null,
        private ?Closure $cache = null,
        private ?Closure $encrypter = null,
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
        $serialization = (string) ($this->config['serialization'] ?? 'php');

        // "native" is backed by PHP's own $_SESSION (see NativeSession) and has
        // no pluggable handler — it's the default while the app/HTMX layer still
        // reads the superglobal directly.
        if ($name === 'native') {
            return new NativeSession($cookie);
        }

        $handler = $this->createHandler($name);

        if ($this->config['encrypt'] ?? false) {
            return new EncryptedStore($cookie, $handler, $this->encrypter(), null, $serialization);
        }

        return new Store($cookie, $handler, null, $serialization);
    }

    private function createHandler(string $name): SessionHandlerInterface
    {
        $lifetime = (int) ($this->config['lifetime'] ?? 120);

        return match ($name) {
            'null'  => new NullSessionHandler(),
            'array' => new ArraySessionHandler($lifetime),
            'file'  => new FileSessionHandler(
                $this->config['files'] ?? sys_get_temp_dir(),
                $lifetime,
            ),
            'cookie' => new CookieSessionHandler(
                $this->cookieJar(),
                $lifetime,
                (bool) ($this->config['expire_on_close'] ?? false),
            ),
            'cache' => new CacheBasedSessionHandler(
                $this->cacheRepository((string) ($this->config['store'] ?? 'file')),
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
        return $this->resolve($this->redis, 'redis', 'Redis')($this->config['connection'] ?? null);
    }

    /** The cookie jar the 'cookie' driver queues onto. */
    private function cookieJar(): CookieJar
    {
        return $this->resolve($this->cookie, 'cookie', 'Cookie')();
    }

    /** The cache store the 'cache' driver writes to. */
    private function cacheRepository(string $store): Repository
    {
        return $this->resolve($this->cache, 'cache', 'Cache')($store);
    }

    /** The encrypter used when session.encrypt is on. */
    private function encrypter(): Encrypter
    {
        return $this->resolve($this->encrypter, 'encrypted', 'Encryption')();
    }

    /**
     * Get a resolver, or explain which provider is missing.
     *
     * @throws RuntimeException When the driver's backing layer was never registered.
     */
    private function resolve(?Closure $resolver, string $driver, string $provider): Closure
    {
        if ($resolver === null) {
            throw new RuntimeException(
                "The {$driver} session driver needs the {$provider} service provider to be "
                    . 'registered, or choose another session driver.'
            );
        }

        return $resolver;
    }
}
